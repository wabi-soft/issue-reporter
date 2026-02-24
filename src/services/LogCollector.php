<?php

namespace wabisoft\craftissuereporter\services;

use Craft;
use craft\base\Component;
use craft\helpers\App;
use wabisoft\craftissuereporter\IssueReporter;
use yii\base\Exception;

class LogCollector extends Component
{
    // Double safety, Craft should have already redacted
    // sensitive values before it gets here
    private const REDACTION_PATTERNS = [
        '/((?:api[_-]?key|token|secret|password|passwd|auth|session[_-]?id|csrf)\s*[=:]\s*)\S+/i',
        '/((?:api[_-]?key|token|secret|password|passwd|auth|session[_-]?id|csrf)"\s*:\s*")[^"]*(")/i',
        '/(Authorization:\s*(?:Bearer|Basic)\s+)\S+/i',
        '/(Cookie:\s*)\S+/i',
        '/((?:mysql|pgsql|postgres|redis|mongodb|amqp|memcached|smtp):\/\/)[^\s]+/i',
        '/\bAKIA[0-9A-Z]{16}\b/',
        '/-----BEGIN\s+(?:RSA\s+)?PRIVATE\s+KEY-----.+?-----END\s+(?:RSA\s+)?PRIVATE\s+KEY-----/s',
    ];

    private const REDACTION_REPLACEMENTS = [
        '$1[REDACTED]',
        '$1[REDACTED]$2',
        '$1[REDACTED]',
        '$1[REDACTED]',
        '$1[REDACTED]',
        '[REDACTED]',
        '[REDACTED]',
    ];

    /**
     * @throws Exception
     */
    public function collect(): array
    {
        $settings = IssueReporter::getInstance()->getSettings();
        $logPath = Craft::$app->getPath()->getLogPath();

        $paths = $this->resolveLogPaths($settings->logFiles, $logPath);

        if (empty($paths)) {
            return [];
        }

        // Only include files modified within the last 48 hours, most recent first
        $cutoff = time() - 172800;
        $paths = array_filter($paths, fn($p) => filemtime($p) >= $cutoff);
        usort($paths, fn($a, $b) => filemtime($b) - filemtime($a));
        $paths = array_slice($paths, 0, (int) App::parseEnv($settings->maxLogFiles));

        $perFileCap = (int) floor((int) App::parseEnv($settings->maxTotalLogSize) / count($paths));
        $results = [];

        foreach ($paths as $path) {
            try {
                $content = $this->readTail($path, (int) App::parseEnv($settings->maxLogFileSize) * 1024);
                $content = $this->filterSeverity($content);
                $content = $this->redact($content);
                $content = $this->truncate($content, $perFileCap);

                if ($content !== '') {
                    $results[basename($path)] = $content;
                }
            } catch (\Throwable $e) {
                Craft::warning("Issue Reporter: Failed to read log file " . basename($path) . ": {$e->getMessage()}", __METHOD__);
            }
        }

        return $results;
    }

    private function resolveLogPaths(array $logFiles, string $logPath): array
    {
        $entries = array_filter(array_column($logFiles, 'pattern'));

        if (empty($entries)) {
            return [];
        }

        $realLogPath = realpath($logPath);
        if ($realLogPath === false) {
            return [];
        }

        $paths = [];

        foreach ($entries as $entry) {
            $entry = basename($entry);

            if (str_contains($entry, '*')) {
                $matched = glob($logPath . DIRECTORY_SEPARATOR . $entry);
                if ($matched !== false) {
                    foreach ($matched as $match) {
                        if ($this->isAllowedPath($match, $realLogPath)) {
                            $paths[] = $match;
                        }
                    }
                }
            } else {
                $path = $logPath . DIRECTORY_SEPARATOR . $entry;
                if ($this->isAllowedPath($path, $realLogPath)) {
                    $paths[] = $path;
                }
            }
        }

        return array_values(array_unique($paths));
    }

    private function isAllowedPath(string $path, string $realLogPath): bool
    {
        $real = realpath($path);

        return $real !== false
            && str_starts_with($real, $realLogPath . DIRECTORY_SEPARATOR)
            && is_file($real)
            && is_readable($real);
    }

    private function filterSeverity(string $content): string
    {
        $lines = explode("\n", $content);
        $filtered = [];
        $keeping = false;

        foreach ($lines as $line) {
            // Monolog entry starts with a timestamp: "2026-02-12 06:34:09 [web.ERROR]"
            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} \[[\w.]+\]/', $line)) {
                $keeping = (bool) preg_match('/\[\w+\.(ERROR|WARNING|CRITICAL|ALERT|EMERGENCY)\]/', $line);
            }

            if ($keeping) {
                $filtered[] = $line;
            }
        }

        return implode("\n", $filtered);
    }

    private function readTail(string $path, int $maxReadBytes): string
    {
        $size = filesize($path);
        if ($size === false || $size === 0) {
            return '';
        }

        $offset = max(0, $size - $maxReadBytes);
        $content = file_get_contents($path, false, null, $offset);

        if ($content === false) {
            return '';
        }

        if ($offset > 0) {
            $firstNewline = strpos($content, "\n");
            if ($firstNewline !== false) {
                $content = substr($content, $firstNewline + 1);
            }
        }

        return $content;
    }

    private function redact(string $content): string
    {
        return preg_replace(self::REDACTION_PATTERNS, self::REDACTION_REPLACEMENTS, $content) ?? $content;
    }

    private function truncate(string $content, int $maxChars): string
    {
        if (mb_strlen($content) <= $maxChars) {
            return $content;
        }

        $content = mb_substr($content, -$maxChars);

        $firstNewline = strpos($content, "\n");
        if ($firstNewline !== false) {
            $content = substr($content, $firstNewline + 1);
        }

        return $content;
    }
}
