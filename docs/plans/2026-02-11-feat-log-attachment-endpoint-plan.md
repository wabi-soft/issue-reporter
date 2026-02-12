---
title: "feat: Add log attachment endpoint for issue submissions"
type: feat
date: 2026-02-11
reviewed: true
---

# feat: Add log attachment endpoint for issue submissions

## Overview

Add a server-side endpoint to the Issue Reporter plugin that returns the tail of configured Craft CMS log files as JSON. The IssueRelay widget calls this endpoint at issue submission time and includes the log data in its payload — giving developers immediate server-side context alongside the user's issue report.

Brainstorm: `docs/brainstorms/2026-02-11-log-attachment-brainstorm.md`

## Proposed Solution

Four changes to the plugin:

1. **`LogController`** — new controller with log-reading logic as private methods. Validates HMAC Bearer token (same token the widget already has). Reads file tails, redacts sensitive data, returns JSON.
2. **`TokenService::validateToken()`** — validates the HMAC token (inverse of existing `generateToken()`)
3. **Settings additions** — `logFiles` string setting
4. **Widget init update** — pass `logsEndpoint` URL in `IssueRelay.init()`

### What Was Cut After Review

- **No `LogService`** — logic lives as private methods on the controller (single caller, no need for a separate service)
- **No Monolog timestamp parsing** — just read the tail of each file. The 256KB tail read is the natural lookback. No time-window filtering, no multi-line grouping, no `logLookbackMinutes` setting.
- **No "current day" filter** — was adding midnight bugs for no benefit
- **No redaction pattern #4** (long base64 strings) — too broad, corrupts useful log data. Three specific patterns are sufficient.

## Technical Approach

### File: `src/services/TokenService.php` (MODIFY)

Add `validateToken(string $token): bool` method — the inverse of existing `generateToken()`.

```php
public function validateToken(string $token): bool
{
    $settings = IssueReporter::getInstance()->getSettings();
    $secret = App::parseEnv($settings->apiSecret);

    if (empty($secret) || str_starts_with($secret, '$')) {
        return false;
    }

    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) {
        return false;
    }

    [$encoded, $signature] = $parts;

    // Verify HMAC signature (constant-time comparison)
    $expectedSignature = hash_hmac('sha256', $encoded, $secret);
    if (!hash_equals($expectedSignature, $signature)) {
        return false;
    }

    // Decode and check expiration
    $payload = json_decode(
        base64_decode(strtr($encoded, '-_', '+/')),
        true
    );

    if (!is_array($payload) || !isset($payload['exp'])) {
        return false;
    }

    if ($payload['exp'] < time()) {
        Craft::warning('Issue Reporter: Expired token on log endpoint.', __METHOD__);
        return false;
    }

    return true;
}
```

Key points:
- `hash_equals()` for constant-time comparison (prevents timing attacks)
- Generic `false` returns — don't distinguish failure reasons to callers
- `Craft::warning()` on expiration for consistency with existing `generateToken()` logging pattern

---

### File: `src/controllers/LogController.php` (NEW)

Extends `craft\web\Controller`. Uses HMAC Bearer token auth (same token the widget already has). Declared as `allowAnonymous` since there is no Craft session — the HMAC token is the auth mechanism.

GET requests in Yii2 do not trigger CSRF validation, so no `beforeAction` override is needed.

```php
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

        $response = $this->asJson(['logs' => $logs]);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    private function collectLogs(): array
    {
        $settings = IssueReporter::getInstance()->getSettings();
        $files = array_filter(array_map('trim', explode(',', $settings->logFiles)));

        if (empty($files)) {
            return [];
        }

        $logPath = Craft::$app->getPath()->getLogPath();
        $perFileCap = (int) floor(self::MAX_TOTAL_CHARS / count($files));
        $results = [];

        foreach ($files as $filename) {
            $filename = basename($filename); // prevent path traversal
            $path = $logPath . DIRECTORY_SEPARATOR . $filename;

            if (!is_file($path) || !is_readable($path)) {
                continue;
            }

            try {
                $content = $this->readTail($path);
                $content = $this->redact($content);
                $content = $this->truncate($content, $perFileCap);

                if ($content !== '') {
                    $results[$filename] = $content;
                }
            } catch (\Throwable $e) {
                Craft::warning("Issue Reporter: Failed to read log file {$filename}: {$e->getMessage()}", __METHOD__);
                continue;
            }
        }

        return $results;
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
```

**Route:** `GET /actions/issue-reporter/log/recent-logs`

**Auth:** HMAC Bearer token via `Authorization` header. The widget already has a valid token from `IssueRelay.init()`.

**HTTP status codes:**
- `200` — success (even if logs are empty: `{ "logs": {} }`)
- `401` — missing, invalid, or expired token

---

### File: `src/models/Settings.php` (MODIFY)

Add one new property — stored as a simple `string`, split by comma where consumed:

```php
public string $logFiles = 'web.log';
```

No additional validation rules needed. The `basename()` call in the controller handles path safety at read time. An empty string disables log fetching.

---

### File: `src/templates/_settings.twig` (MODIFY)

Add a new "Log Settings" section after the existing settings, following the established `<h2>` + `<hr>` pattern:

```twig
<hr>
<h2>Log Settings</h2>

{{ forms.textField({
    label: 'Log Files',
    instructions: 'Comma-separated list of log filenames from `storage/logs/` to include with issue submissions. Leave empty to disable. Example: `web.log, queue.log`',
    id: 'logFiles',
    name: 'logFiles',
    value: settings.logFiles,
    errors: settings.getErrors('logFiles'),
}) }}
```

No array/string conversion needed — `logFiles` is a string in the model and a string in the form.

---

### File: `src/twig/Extension.php` (MODIFY)

Update `buildWidgetHtml()` to include `logsEndpoint` in the `IssueRelay.init()` call when `logFiles` is non-empty. Use `json_encode()` for the init config instead of string concatenation:

```php
use craft\helpers\UrlHelper;

// Replace the existing IssueRelay.init({ token: '{$token}' }) with:
$initConfig = ['token' => $token];

$settings = IssueReporter::getInstance()->getSettings();
if (trim($settings->logFiles) !== '') {
    $initConfig['logsEndpoint'] = UrlHelper::actionUrl('issue-reporter/log/recent-logs');
}

$initConfigJson = json_encode($initConfig, JSON_UNESCAPED_SLASHES);

// In the heredoc:
// IssueRelay.init({$initConfigJson});
```

Note: `$settings` is already available in `buildWidgetHtml()` — reuse it rather than fetching again.

If `logFiles` is empty, `logsEndpoint` is omitted from init — backwards compatible.

---

### File: `src/IssueReporter.php` (NO CHANGES)

No `LogService` to register. The controller is auto-discovered by Craft.

---

## IssueRelay Widget Changes

The widget JS needs to be updated (in the IssueRelay project) to:

1. Recognize `logsEndpoint` in the init config
2. Before submission, call `GET {logsEndpoint}` with Bearer token in `Authorization` header
3. Include the response `logs` object in the submission payload as `serverLogs`
4. Handle failure gracefully (timeout, error → submit without logs)

See: `docs/brainstorms/2026-02-11-issuerelay-log-support-plan.md` (also copied to the IssueRelay backend repo)

---

## Acceptance Criteria

- [x] `GET /actions/issue-reporter/log/recent-logs` with valid Bearer token returns `{ "logs": { ... } }`
- [x] Endpoint returns `401` for missing, invalid, or expired tokens
- [x] Endpoint returns `200` with `{ "logs": {} }` when no log files have content
- [x] Missing/unreadable log files are silently skipped with a `Craft::warning()`
- [x] Sensitive data (API keys, passwords, auth headers, connection strings) is redacted as `[REDACTED]`
- [x] Path traversal in `logFiles` config is prevented via `basename()`
- [x] Per-file size is capped at `floor(50000 / N)` characters; oldest content (beginning of file) is truncated first
- [x] Partial lines at truncation boundaries are discarded
- [x] Large log files are read efficiently (last 256KB only via `file_get_contents` with offset)
- [x] `logFiles` is configurable in plugin settings as a comma-separated string
- [x] `logsEndpoint` URL is included in `IssueRelay.init()` when `logFiles` is non-empty
- [x] `logsEndpoint` is omitted from `init()` when `logFiles` is empty
- [x] Response includes `Cache-Control: no-store, private` and `X-Content-Type-Options: nosniff` headers
- [x] Init config uses `json_encode()` instead of string concatenation
- [x] `TokenService::validateToken()` uses `hash_equals()` for constant-time comparison

## Implementation Order

1. **`TokenService.php`** — add `validateToken()` method
2. **`Settings.php`** — add `logFiles` string property
3. **`_settings.twig`** — add Log Settings section
4. **`LogController.php`** — the new controller with all log logic
5. **`Extension.php`** — pass `logsEndpoint` to widget init using `json_encode()`

Five steps, four files modified, one file created.

## Known Limitations

- **No timestamp filtering:** Returns the tail of each file (last 256KB). On very busy sites, this may include entries older than 10 minutes. On quiet sites, it may include entries older than desired. This is acceptable — the developer reading the issue can scan timestamps easily.
- **Read cap:** Only the last ~256KB of each log file is read. In practice, 256KB covers well beyond 10 minutes of logs for typical Craft applications.
- **Non-standard log formats:** Logs are returned as raw text without parsing. Any format works — Monolog, custom loggers, PHP error logs. This is a feature, not a limitation.

## References

- Brainstorm: `docs/brainstorms/2026-02-11-log-attachment-brainstorm.md`
- IssueRelay plan: `docs/brainstorms/2026-02-11-issuerelay-log-support-plan.md`
- Existing TokenService: `src/services/TokenService.php`
- Existing Extension: `src/twig/Extension.php`
- Existing Settings: `src/models/Settings.php`
