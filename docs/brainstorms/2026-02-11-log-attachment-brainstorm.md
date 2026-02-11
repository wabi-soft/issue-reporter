# Log Attachment for Issue Submissions

**Date:** 2026-02-11
**Status:** Design Complete

## What We're Building

A server-side AJAX endpoint in the Issue Reporter plugin that collects recent Craft CMS log entries and returns them to the IssueRelay widget at issue submission time. The widget calls this endpoint via a pre-submission callback, receives log content, and includes it in the payload sent to IssueRelay — which appends it to the GitHub issue body.

### User Story

When a CMS user submits an issue through the IssueRelay widget, the system automatically collects recent log entries from configured Craft log files and attaches them to the submission — giving developers immediate context about what was happening on the server around the time the issue was reported.

## Why This Approach

- **AJAX endpoint over pre-loaded data:** Logs are fetched fresh at submission time, not stale from page load. A user might browse for 20 minutes before reporting — we want the logs from the actual moment of submission.
- **Reuse HMAC token auth:** The widget already has a valid signed token. No need for a separate auth mechanism; the new endpoint validates the same token format.
- **Configurable log files and lookback window:** Different Craft installations have different logging setups. Admins can specify which files to scan and how far back to look.
- **Init callback pattern:** The plugin passes a `logsEndpoint` URL in `IssueRelay.init()`. The widget (IssueRelay side, to be built) calls this endpoint before submission and includes the response in its payload.

## Key Decisions

1. **Log delivery method:** AJAX endpoint (controller action) called by the widget JS via a pre-submission callback
2. **Authentication:** Reuse existing HMAC token validation from `TokenService`
3. **Log scope:** Configurable list of log file names (default: `['web.log']`)
4. **Time window:** Configurable lookback (default: 10 minutes, range: 1–60 minutes)
5. **Day boundary:** Only read entries from the current day (based on Monolog timestamps)
6. **Log format:** Standard Craft/Monolog format (`[2026-02-11T14:30:00+00:00] channel.LEVEL: message`)
7. **Multi-line entries:** Continuation lines (no timestamp prefix, e.g., stack traces) are grouped with the preceding timestamped entry
8. **Size handling:** Truncate oldest entries first if total content exceeds safe limit (~50K chars, leaving headroom for user message + browser info within GitHub's 65K limit)
9. **Issue type:** Logs collected for ALL issue types — IssueRelay decides whether to include them
10. **Wrap in `<details>` tag:** IssueRelay wraps logs in a collapsible `<details><summary>Server Logs</summary>` block (IssueRelay side)
11. **Basic sanitization:** Redact common sensitive patterns (API keys, passwords, tokens, connection strings) before returning logs

## Data Flow

```
1. Plugin renders widget init:
   IssueRelay.init({ token: '...', logsEndpoint: '/actions/issue-reporter/logs/recent' })

2. User fills out issue form in widget

3. Widget calls logsEndpoint with token header (IssueRelay side, to be built)

4. Plugin endpoint validates token, collects & sanitizes logs, returns JSON

5. Widget includes log data in submission payload to IssueRelay API

6. IssueRelay appends logs to GitHub issue body in <details> block
```

## Content Priority in Issue Body

The log data is **supplementary** and must come AFTER the primary content:

1. **User's description/message** (highest priority)
2. **Browser information** (user agent, viewport, URL, etc.)
3. **Other metadata** from IssueRelay
4. **Server logs** (lowest priority — appended at the end, in a collapsible `<details>` block)

If the combined content approaches the 65K character limit, logs are the first thing truncated. The user's own words and browser context always take precedence.

## Security

- **No exposure to standard users:** The widget only renders for logged-in CP users with `accessCp` permission (and optionally restricted to specific user groups). The log endpoint requires a valid HMAC token — unauthenticated requests are rejected.
- **Basic redaction:** Before returning logs, the service redacts common sensitive patterns:
  - API keys / tokens (long hex/base64 strings after `key=`, `token=`, `Authorization:`, etc.)
  - Passwords (`password=`, `passwd=`, `secret=`)
  - Database connection strings (`mysql://`, `pgsql://` with credentials)
- **Logs go to private GitHub repo:** Only repository collaborators see the log content in the issue.
- **Admin awareness:** Documentation notes that admins should be aware of what their logs may contain.

## New Components (Plugin Side)

### Controller: `LogController`
- Action: `actionRecentLogs`
- Route: `issue-reporter/logs/recent`
- Validates HMAC token from `Authorization` header
- Returns JSON: `{ "logs": { "web.log": "...", "queue.log": "..." } }`
- Returns empty `{ "logs": {} }` silently if files are missing or unreadable

### Service: `LogService`
- `getRecentLogs(): array` — main method
- Reads configured log files from Craft's `storage/logs/` directory
- Parses Monolog timestamps, filters to entries within lookback window AND current day
- Groups multi-line entries (stack traces) with their parent timestamped line
- Applies basic sensitive data redaction
- Truncates oldest entries if total exceeds size cap
- Returns associative array of filename => log content string

### Settings Additions
- `logFiles`: `array` — list of log filenames to scan (default: `['web.log']`)
- `logLookbackMinutes`: `int` — lookback window in minutes (default: `10`, min: `1`, max: `60`)

### Token Validation (added to `TokenService`)
- `validateToken(string $token): bool`
- Verifies HMAC signature using `apiSecret`
- Checks `exp` claim has not passed

### Widget Init Update (in `Extension.php`)
- Add `logsEndpoint` URL to the `IssueRelay.init()` config object
- URL points to the Craft action route: `UrlHelper::actionUrl('issue-reporter/logs/recent')`

## Work Required on IssueRelay Side (Separate Project)

These items are NOT part of this plugin — they need to be built in IssueRelay:

1. **Recognize `logsEndpoint` in init config**
2. **Pre-submission callback:** Before submitting, call the `logsEndpoint` with the token in an `Authorization` header
3. **Include log data in submission payload** to IssueRelay API
4. **Append logs to GitHub issue body** in a `<details>` block with markdown code fencing
5. **Handle endpoint failure gracefully** — if the log fetch fails, submit the issue without logs

## Resolved Questions

1. **Log content format:** Raw text. IssueRelay handles formatting (code fences, `<details>` wrapping).
2. **Missing log files:** Silently skip — omit from the response.

## Out of Scope

- Direct GitHub API integration (stays with IssueRelay)
- Log file rotation management
- Real-time log streaming
- Log level filtering (grab everything within the time window)
- Advanced sanitization / PII detection
