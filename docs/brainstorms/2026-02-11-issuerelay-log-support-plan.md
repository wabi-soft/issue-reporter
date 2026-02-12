# IssueRelay: Server Log Support Implementation Plan

**Date:** 2026-02-11
**Context:** The Craft CMS Issue Reporter plugin is adding a server-side endpoint that returns recent log entries. IssueRelay needs to consume this endpoint and include the log data in GitHub issues.

## Overview

The Issue Reporter plugin will pass a `logsEndpoint` URL in `IssueRelay.init()`. The widget needs to call this endpoint before submitting an issue, then include the returned log data in the GitHub issue body — appended at the end in a collapsible `<details>` block.

## What the Plugin Sends

The `IssueRelay.init()` call will include a new optional property:

```js
IssueRelay.init({
  token: 'existing-hmac-token',
  logsEndpoint: 'https://example.com/actions/issue-reporter/logs/recent-logs'  // NEW
});
```

- `logsEndpoint` is optional — if absent, skip log fetching entirely (backwards compatible).
- The URL points to a Craft CMS action route on the same origin as the site.

## Endpoint Contract

### Request

```
GET {logsEndpoint}
Authorization: Bearer {token}
Accept: application/json
```

The `token` is the same HMAC token already used by the widget. Send it in the `Authorization` header as a Bearer token.

### Response

```json
{
  "logs": {
    "web.log": "[2026-02-11T14:25:00+00:00] web.ERROR: Some error...\nStack trace:\n#0 ...",
    "queue.log": "[2026-02-11T14:28:00+00:00] queue.WARNING: Job failed..."
  }
}
```

- `logs` is an object where keys are log file names and values are raw text strings.
- Logs are the tail of each configured file (last ~256KB), already redacted and size-capped (~50K total).
- If no logs exist or all configured files are missing, `logs` will be an empty object `{}`.

### Error Handling

- **Non-200 response (401 if token invalid/expired):** Proceed with issue submission without logs. Do not block issue creation.
- **Network timeout:** Use a reasonable timeout (5 seconds). If it expires, submit without logs.
- **Empty logs:** If `logs` is `{}`, omit the log section from the issue body entirely.

## Widget Changes

### 1. Accept `logsEndpoint` in Init Config

When `IssueRelay.init()` is called, store the `logsEndpoint` value if provided.

### 2. Pre-Submission Log Fetch

Before the widget submits the issue to IssueRelay's API, check if `logsEndpoint` is configured. If so:

```js
async function fetchServerLogs(logsEndpoint, token) {
  try {
    const response = await fetch(logsEndpoint, {
      method: 'GET',
      headers: {
        'Authorization': `Bearer ${token}`,
        'Accept': 'application/json'
      },
      signal: AbortSignal.timeout(5000) // 5 second timeout
    });

    if (!response.ok) return null;

    const data = await response.json();
    return data.logs && Object.keys(data.logs).length > 0 ? data.logs : null;
  } catch (e) {
    // Network error or timeout — proceed without logs
    return null;
  }
}
```

### 3. Include Logs in Submission Payload

Pass the fetched logs to IssueRelay's API as part of the issue submission payload. Add a new field:

```json
{
  "title": "...",
  "body": "...",
  "browser": { ... },
  "serverLogs": {
    "web.log": "...",
    "queue.log": "..."
  }
}
```

`serverLogs` is optional / nullable. If log fetch failed, omit it or send `null`.

## IssueRelay API / Backend Changes

### 4. Append Logs to GitHub Issue Body

When creating the GitHub issue, if `serverLogs` is present and non-empty, append a log section **at the very end** of the issue body, after all other content (user description, browser info, metadata).

Format:

```markdown

---

<details>
<summary>Server Logs</summary>

**web.log**
```
[2026-02-11T14:25:00+00:00] web.ERROR: Some error message
Stack trace:
#0 /var/www/html/vendor/...
```

**queue.log**
```
[2026-02-11T14:28:00+00:00] queue.WARNING: Job failed
```

</details>
```

Rules:
- Each log file gets its own section with the filename as a bold header
- Log content goes in a fenced code block (triple backticks)
- The entire section is wrapped in `<details>` so it's collapsed by default
- Separated from the rest of the issue body by a horizontal rule (`---`)

### 5. Respect GitHub's Character Limit

GitHub issue bodies have a 65,536 character limit. The plugin already truncates logs on its side, but IssueRelay should also check:

1. Build the full issue body (user message + browser info + metadata)
2. Calculate remaining character budget: `65536 - currentBodyLength - 500` (500 chars buffer for the `<details>` wrapper, headers, code fences)
3. If the total log content exceeds the remaining budget, truncate from the **beginning** of each log (oldest entries first)
4. If there's no room at all, omit the log section entirely

### 6. Content Priority (Do Not Sacrifice)

Never truncate user content to make room for logs. Priority order:

1. User's description/message — **never truncate**
2. Browser information — **never truncate**
3. Other metadata — **never truncate**
4. Server logs — truncate or omit if necessary

## Backwards Compatibility

- `logsEndpoint` is optional in `init()`. Existing integrations without it continue to work unchanged.
- `serverLogs` is optional in the submission payload. The API should handle its absence gracefully.
- No changes to the token format or authentication flow.

## Testing Notes

Since log fetching is best-effort and non-blocking:

- **Happy path:** `logsEndpoint` provided → logs fetched → included in issue body
- **No endpoint:** `logsEndpoint` omitted → no log fetch → issue created normally
- **Endpoint fails (401, 500, timeout, network error):** Issue created normally without logs, no error shown to user
- **Empty logs:** `logs: {}` → no log section in issue body
- **Large logs:** Verify truncation works and issue body stays under 65K chars
- **CORS:** The endpoint is on the same origin as the widget (both on the Craft site). The widget JS is loaded via `<script src="...">` and executes in the host page's origin context, making the `fetch()` a same-origin request. CORS is not an issue.
