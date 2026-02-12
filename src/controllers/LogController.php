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
        '/((?:api[_-]?key|token|secret|password|passwd|auth)\s*[=:]\s*)\S+/i',
        '/(Authorization:\s*(?:Bearer|Basic)\s+)\S+/i',
        '/((?:mysql|pgsql|postgres|redis|mongodb):\/\/)[^\s]+/i',
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

        $paths = [];

        foreach ($entries as $entry) {
            $entry = basename($entry); // prevent path traversal

            if (str_contains($entry, '*')) {
                // Glob pattern — match files in the log directory
                $matched = glob($logPath . DIRECTORY_SEPARATOR . $entry);
                if ($matched !== false) {
                    foreach ($matched as $match) {
                        if (is_file($match) && is_readable($match)) {
                            $paths[] = $match;
                        }
                    }
                }
            } else {
                // Explicit filename
                $path = $logPath . DIRECTORY_SEPARATOR . $entry;
                if (is_file($path) && is_readable($path)) {
                    $paths[] = $path;
                }
            }
        }

        return $paths;
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
        foreach (self::REDACTION_PATTERNS as $pattern) {
            $content = preg_replace($pattern, '$1[REDACTED]', $content);
        }

        return $content;
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
