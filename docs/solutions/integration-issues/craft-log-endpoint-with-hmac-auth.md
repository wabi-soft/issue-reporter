---
title: "Building a secure log endpoint in a CraftCMS 5 plugin"
category: integration-issues
tags: [craftcms, yii2, hmac, authentication, log-files, security, controller]
module: issue-reporter
date: 2026-02-11
symptoms:
  - Need to expose server log data via an authenticated API endpoint
  - Widget JS needs to call a plugin endpoint with token auth (not session auth)
  - Log files may contain sensitive data that needs redaction before transmission
---

# Building a Secure Log Endpoint in a CraftCMS 5 Plugin

## Problem

We needed to add a server-side endpoint to the Issue Reporter plugin that returns recent Craft CMS log file contents as JSON. The endpoint is called by the IssueRelay widget (client-side JS running on the same origin) using an HMAC Bearer token for authentication. Key challenges:

1. Authenticating requests without Craft session (widget uses HMAC tokens)
2. Reading log file tails efficiently without loading entire files
3. Preventing path traversal via user-configured log filenames
4. Redacting sensitive data before transmission
5. Staying within GitHub's 65K character limit for issue bodies

## Investigation & Decisions

### Auth: HMAC Token vs Craft Session

Initially considered switching to Craft session auth (simpler, no `allowAnonymous` needed). However, the consuming service (IssueRelay) was already built around Bearer token auth and couldn't easily change. **Lesson: consider the full integration chain before changing auth patterns.**

### Architecture: Service vs Controller Private Methods

Three reviewers unanimously recommended putting log-reading logic as private methods on the controller rather than a separate `LogService` class. The controller is the only consumer — extracting a service adds indirection for no benefit.

### Log Parsing: Skip It

Original design included Monolog timestamp parsing, time-window filtering, current-day filtering, and multi-line grouping. All three reviewers recommended cutting this in favor of simple file tail reads. The 256KB tail read *is* the natural lookback window. **Lesson: raw file tails are simpler and format-agnostic.**

### Settings: String Not Array

Store `logFiles` as a simple comma-separated string (`"*.log"`), split at consumption time. Avoids array/string type conversion complexity in Craft's settings layer.

## Solution

### 1. Controller with `allowAnonymous`

```php
class LogController extends Controller
{
    // HMAC token auth, not session — must be anonymous
    protected array|int|bool $allowAnonymous = ['recent-logs'];
```

**Key insight:** GET requests in Yii2 do NOT trigger CSRF validation. No `beforeAction()` override is needed for a GET-only endpoint.

### 2. Token Validation (symmetric with generation)

```php
public function validateToken(string $token): bool
{
    // ... parse secret, split token into encoded.signature ...

    // Constant-time comparison prevents timing attacks
    $expectedSignature = hash_hmac('sha256', $encoded, $secret);
    if (!hash_equals($expectedSignature, $signature)) {
        return false;
    }

    // Reverse base64url encoding (must match generateToken)
    $payload = json_decode(
        base64_decode(strtr($encoded, '-_', '+/')),
        true
    );
    // ... check exp claim ...
}
```

**Key details:**
- `hash_equals()` for constant-time HMAC comparison (timing attack prevention)
- `strtr($encoded, '-_', '+/')` reverses the base64url encoding from `generateToken()`
- Return generic `false` — don't leak failure reasons to callers

### 3. Efficient File Tail Reading

```php
private function readTail(string $path): string
{
    $size = filesize($path);
    $offset = max(0, $size - self::MAX_READ_BYTES); // 256KB
    $content = file_get_contents($path, false, null, $offset);

    // Discard first partial line if we started mid-file
    if ($offset > 0) {
        $firstNewline = strpos($content, "\n");
        if ($firstNewline !== false) {
            $content = substr($content, $firstNewline + 1);
        }
    }
    return $content;
}
```

`file_get_contents()` with offset is simpler than `SplFileObject` and avoids loading entire files.

### 4. Path Traversal Prevention

```php
$entry = basename($entry); // "../../etc/passwd" → "passwd"
```

Applied to every user-configured filename before constructing paths. Simple, bulletproof.

### 5. Glob Pattern Support

```php
if (str_contains($entry, '*')) {
    $matched = glob($logPath . DIRECTORY_SEPARATOR . $entry);
    // ... filter with is_file() && is_readable() ...
}
```

Default `*.log` picks up Craft's date-stamped rotated files automatically.

### 6. Widget Init with `json_encode()`

```php
$initConfig = ['token' => $token];
if (trim($settings->logFiles) !== '') {
    $initConfig['logsEndpoint'] = UrlHelper::actionUrl('issue-reporter/logs/recent-logs');
}
$initConfigJson = json_encode($initConfig, JSON_UNESCAPED_SLASHES);
// In heredoc: IssueRelay.init({$initConfigJson});
```

**Always use `json_encode()` for JS init configs in PHP heredocs** — string concatenation is fragile and risks XSS.

## Prevention & Best Practices

### Yii2/Craft Controller Gotchas

| Gotcha | Correct approach |
|--------|-----------------|
| `$response->setHeaders([...])` | Does NOT exist. Use `$response->headers->set('Key', 'value')` per header |
| CSRF on GET endpoints | Yii2 skips CSRF for GET — no `beforeAction()` override needed |
| Error responses with status codes | Call `Craft::$app->getResponse()->setStatusCode(401)` BEFORE `$this->asJson()` |

### Redaction Patterns

Three specific patterns are sufficient. Avoid broad patterns (e.g., "any long base64 string") — they corrupt stack traces and encoded data in logs.

```php
private const REDACTION_PATTERNS = [
    '/((?:api[_-]?key|token|secret|password|passwd|auth)\s*[=:]\s*)\S+/i',
    '/(Authorization:\s*(?:Bearer|Basic)\s+)\S+/i',
    '/((?:mysql|pgsql|postgres|redis|mongodb):\/\/)[^\s]+/i',
];
```

### Size Capping Strategy

- **Global cap** (50K chars) divided equally across matched files
- **Truncate from beginning** (oldest entries) — most recent logs are most relevant
- **Discard partial lines** at truncation boundaries
- With many glob matches, per-file budget shrinks — acceptable trade-off for typical Craft installs (2-5 log files)

## Cross-References

- Implementation plan: `docs/plans/2026-02-11-feat-log-attachment-endpoint-plan.md`
- Brainstorm: `docs/brainstorms/2026-02-11-log-attachment-brainstorm.md`
- IssueRelay-side plan: `docs/brainstorms/2026-02-11-issuerelay-log-support-plan.md`
- Source files: `src/controllers/LogController.php`, `src/services/TokenService.php`, `src/twig/Extension.php`
