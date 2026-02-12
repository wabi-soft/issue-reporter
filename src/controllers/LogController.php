<?php

namespace wabisoft\craftissuereporter\controllers;

use Craft;
use craft\web\Controller;
use wabisoft\craftissuereporter\IssueReporter;
use yii\web\Response;

class LogController extends Controller
{
    protected array|int|bool $allowAnonymous = ['recent-logs'];

    private const MAX_READ_BYTES = 262144; // 256KB per file
    private const MAX_TOTAL_CHARS = 50000; // 50K global cap

    private const REDACTION_PATTERNS = [
        // Credentials in key=value or key: value format
        '/((?:api[_-]?key|token|secret|password|passwd|auth|session[_-]?id|csrf)\s*[=:]\s*)\S+/i',
        // Credentials in JSON "key": "value" format
        '/((?:api[_-]?key|token|secret|password|passwd|auth|session[_-]?id|csrf)"\s*:\s*")[^"]*(")/i',
        // Authorization headers
        '/(Authorization:\s*(?:Bearer|Basic)\s+)\S+/i',
        // Cookie headers
        '/(Cookie:\s*)\S+/i',
        // Database/service connection strings
        '/((?:mysql|pgsql|postgres|redis|mongodb|amqp|memcached|smtp):\/\/)[^\s]+/i',
        // AWS access keys (AKIA...)
        '/\bAKIA[0-9A-Z]{16}\b/',
        // PEM private keys
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

    public function actionRecentLogs(): Response
    {
        $this->requireAcceptsJson();

        // Validate HMAC Bearer token
        $authHeader = Craft::$app->getRequest()->getHeaders()->get('Authorization');
        $token = null;
        if ($authHeader && preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            $token = $matches[1];
        }

        if (!$token || !IssueReporter::getInstance()->tokenService->validateToken($token)) {
            Craft::$app->getResponse()->setStatusCode(401);
            return $this->asJson(['error' => 'Unauthorized']);
        }

        $logs = $this->collectLogs();

        $response = $this->asJson(['logs' => $logs ?: new \stdClass()]);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    private function collectLogs(): array
    {
        $settings = IssueReporter::getInstance()->getSettings();
        $logPath = Craft::$app->getPath()->getLogPath();

        $paths = $this->resolveLogPaths($settings->logFiles, $logPath);

        if (empty($paths)) {
            return [];
        }

        $perFileCap = (int) floor(self::MAX_TOTAL_CHARS / count($paths));
        $results = [];

        foreach ($paths as $path) {
            try {
                $content = $this->readTail($path);
                $content = $this->redact($content);
                $content = $this->truncate($content, $perFileCap);

                if ($content !== '') {
                    $results[basename($path)] = $content;
                }
            } catch (\Throwable $e) {
                Craft::warning("Issue Reporter: Failed to read log file " . basename($path) . ": {$e->getMessage()}", __METHOD__);
                continue;
            }
        }

        return $results;
    }

    /**
     * Resolve the logFiles setting into an array of readable file paths.
     * Supports glob patterns (e.g., *.log) and comma-separated filenames.
     *
     * @return string[]
     */
    private function resolveLogPaths(string $logFiles, string $logPath): array
    {
        $entries = array_filter(array_map('trim', explode(',', $logFiles)));

        if (empty($entries)) {
            return [];
        }

        $realLogPath = realpath($logPath);
        if ($realLogPath === false) {
            return [];
        }

        $paths = [];

        foreach ($entries as $entry) {
            $entry = basename($entry); // prevent path traversal

            if (str_contains($entry, '*')) {
                // Glob pattern — match files in the log directory
                $matched = glob($logPath . DIRECTORY_SEPARATOR . $entry);
                if ($matched !== false) {
                    foreach ($matched as $match) {
                        if ($this->isAllowedPath($match, $realLogPath)) {
                            $paths[] = $match;
                        }
                    }
                }
            } else {
                // Explicit filename
                $path = $logPath . DIRECTORY_SEPARATOR . $entry;
                if ($this->isAllowedPath($path, $realLogPath)) {
                    $paths[] = $path;
                }
            }
        }

        return $paths;
    }

    /**
     * Verify a path is a readable file that resolves within the log directory.
     * Prevents symlink attacks that could read files outside storage/logs/.
     */
    private function isAllowedPath(string $path, string $realLogPath): bool
    {
        $real = realpath($path);

        return $real !== false
            && str_starts_with($real, $realLogPath . DIRECTORY_SEPARATOR)
            && is_file($real)
            && is_readable($real);
    }

    private function readTail(string $path): string
    {
        $size = filesize($path);
        if ($size === false || $size === 0) {
            return '';
        }

        $offset = max(0, $size - self::MAX_READ_BYTES);
        $content = file_get_contents($path, false, null, $offset);

        if ($content === false) {
            return '';
        }

        // If we read from an offset (not the beginning), discard the first
        // partial line since we likely landed mid-line
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
        return preg_replace(self::REDACTION_PATTERNS, self::REDACTION_REPLACEMENTS, $content);
    }

    private function truncate(string $content, int $maxChars): string
    {
        if (mb_strlen($content) <= $maxChars) {
            return $content;
        }

        // Truncate from the beginning (oldest entries)
        $content = mb_substr($content, -$maxChars);

        // Find the first complete line (discard the partial one)
        $firstNewline = strpos($content, "\n");
        if ($firstNewline !== false) {
            $content = substr($content, $firstNewline + 1);
        }

        return $content;
    }
}
