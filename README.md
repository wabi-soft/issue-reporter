# Issue Reporter

Craft CMS plugin that injects the [IssueRelay](https://issuerelay.com) feedback widget for logged-in CP users. Everyone else gets no script, no request, and no cookie.

## Setup

1. Install the plugin
2. Go to **Settings > Issue Reporter** in the CP
3. Paste your Host URL, Project UUID, API Key, and API Secret from the IssueRelay dashboard
4. Save

The widget automatically appears on all front-end pages for users with CP access. No template changes needed.

## Who sees the widget

**Show Widget To** (`injectFor`) sets the audience. It defaults to `cpAccess`.

| Value | Who gets the loader |
| --- | --- |
| `cpAccess` | Users with CP access. Anonymous pages contain no widget markup. |
| `always` | Everyone. The widget still initializes only for authorized users. |
| `never` | Nobody, including `{{ issueRelayWidget() }}`. |

**Auto-inject Widget** (`autoInject`) is a different question: automatic injection, or manual `{{ issueRelayWidget() }}` placement. Both honor `injectFor`.

`allowedUserGroups` narrows the audience further. Leave it empty to allow every user with CP access.

The browser fetches `widget.js` only after the plugin authorizes the visitor. Under `always`, an unauthorized visitor gets the loader but no script and no third-party cookie.

## Environment Variables

All string and numeric settings support `$ENV_VAR` syntax directly in the CP settings fields. Boolean settings support env vars via the dropdown menu.

```shell
ISSUE_RELAY_HOST_URL=https://issuerelay.com
ISSUE_RELAY_PROJECT_UUID=your-uuid
ISSUE_RELAY_API_SECRET=your-secret
```

Then in plugin settings, enter `$ISSUE_RELAY_HOST_URL`, `$ISSUE_RELAY_PROJECT_UUID`, `$ISSUE_RELAY_API_SECRET`. The placeholders show these names by default.

`injectFor` is a dropdown in the CP. To drive it from an env var, set it in `config/issue-reporter.php` instead.

## Config File Overrides

Create `config/issue-reporter.php` to override any setting. Config file values take precedence over CP settings.

```php
<?php

use craft\helpers\App;

return [
    'hostUrl' => App::env('ISSUE_RELAY_HOST_URL'),
    'projectUuid' => App::env('ISSUE_RELAY_PROJECT_UUID'),
    'apiSecret' => App::env('ISSUE_RELAY_API_SECRET'),
    'tokenTtl' => 3600,
    'autoInject' => true,
    'injectFor' => 'cpAccess',
    'includeCraftContext' => true,
    'primaryColor' => App::env('ISSUE_RELAY_PRIMARY_COLOR'),
    'primaryHoverColor' => null,
    'maxLogFiles' => 5,
    'maxLogFileSize' => 32,
    'maxTotalLogSize' => 10000,
    'allowedUserGroups' => [],
    'logFiles' => [
        ['pattern' => 'web.log'],
        ['pattern' => 'console-*.log'],
    ],
];
```

Settings overridden by the config file appear as disabled with a warning in the CP.

## Static caching

Injection happens inside the cached render, so a full-page cache stores whatever the audience check decided. That holds up because Blitz and friends skip the cache for requests carrying a session cookie, and those are the only requests that can receive the widget.

Configure a cache to serve cached HTML to logged-in users and the widget stops appearing for them. Known limitation, not something to patch around on the client.

Clear the page cache when you upgrade. Anonymous visitors keep getting the old markup until cached pages turn over.

## Serving the widget from your own origin

Running `injectFor: always`? Point `hostUrl` at a CDN or Cloudflare Worker route on your own domain. A first-party `widget.js` sets first-party cookies, which keeps Chrome's third-party-cookie audits quiet and leaves the request visible to your consent manager.

## Requirements

- Craft CMS 5.9+
- PHP 8.2+
