# Issue Reporter

Craft CMS plugin that injects the [IssueRelay](https://issuerelay.com) feedback widget for logged-in CP users. Visitors outside that audience receive no script, no request, and no cookie.

## Setup

1. Install the plugin
2. Go to **Settings > Issue Reporter** in the CP
3. Paste your Host URL, Project UUID, API Key, and API Secret from the IssueRelay dashboard
4. Save

The widget automatically appears on all front-end pages for users with CP access. No template changes needed.

## Who sees the widget

**Show Widget To** (`injectFor`) decides the audience. It defaults to `cpAccess`, and nothing is rendered for anyone outside it.

| Value | Behavior |
| --- | --- |
| `cpAccess` | Default. Only requests from a user with CP access get the loader. Anonymous page views contain no widget markup at all. |
| `always` | Every page gets the loader. The widget still only initializes for authorized users, and `widget.js` is never fetched for anyone else. |
| `never` | Off everywhere, including `{{ issueRelayWidget() }}`. |

**Auto-inject Widget** (`autoInject`) is a separate axis: it chooses automatic injection against manual `{{ issueRelayWidget() }}` placement. Both paths honor `injectFor`.

`allowedUserGroups` narrows within the audience. Leave it empty to allow every user with CP access.

The external `widget.js` is only requested after the plugin authorizes the visitor, so an unauthorized page view makes no cross-origin request and sets no third-party cookie.

## Environment Variables

All string and numeric settings support `$ENV_VAR` syntax directly in the CP settings fields. Boolean settings support env vars via the dropdown menu.

```shell
ISSUE_RELAY_HOST_URL=https://issuerelay.com
ISSUE_RELAY_PROJECT_UUID=your-uuid
ISSUE_RELAY_API_SECRET=your-secret
```

Then in plugin settings, enter `$ISSUE_RELAY_HOST_URL`, `$ISSUE_RELAY_PROJECT_UUID`, `$ISSUE_RELAY_API_SECRET`. The placeholders show these names by default.

`injectFor` is a dropdown in the CP, so point it at an env var through `config/issue-reporter.php` rather than the settings screen.

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

Injection happens inside the cached render, so the audience decision is baked into whatever a full-page cache stores. This works because caches like Blitz bypass the cache for requests carrying a session cookie, which is every request that could legitimately receive the widget.

Where a cache is configured to serve cached HTML to logged-in users, the widget will not appear for them. That is a known limitation, not a bug to work around client-side.

Clear the page cache after upgrading. Until cached pages turn over, anonymous visitors keep receiving the old markup.

## Serving the widget from your own origin

Installs that genuinely want the widget on public pages (`injectFor: always`) can point `hostUrl` at a CDN or Cloudflare Worker route on their own domain. Serving `widget.js` first-party keeps its cookies first-party, which clears Chrome's third-party-cookie reporting and keeps the request inside a consent manager's reach.

## Requirements

- Craft CMS 5.9+
- PHP 8.2+
