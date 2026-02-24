# Issue Reporter

Craft CMS plugin that injects the [IssueRelay](https://issuerelay.com) feedback widget for logged-in CP users.

## Setup

1. Install the plugin
2. Go to **Settings > Issue Reporter** in the CP
3. Paste your Host URL, Project UUID, API Key, and API Secret from the IssueRelay dashboard
4. Save

The widget automatically appears on all front-end pages for users with CP access. No template changes needed.

## Environment Variables

All string and numeric settings support `$ENV_VAR` syntax directly in the CP settings fields. Boolean settings support env vars via the dropdown menu.

```
ISSUE_RELAY_HOST_URL=https://issuerelay.com
ISSUE_RELAY_PROJECT_UUID=your-uuid
ISSUE_RELAY_API_SECRET=your-secret
```

Then in plugin settings, enter `$ISSUE_RELAY_HOST_URL`, `$ISSUE_RELAY_PROJECT_UUID`, `$ISSUE_RELAY_API_SECRET`. The placeholders show these names by default.

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
    'includeCraftContext' => true,
    'primaryColor' => App::env('ISSUE_REPORTER_PRIMARY_COLOR'),
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

## Requirements

- Craft CMS 5.9+
- PHP 8.2+
