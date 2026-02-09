# Issue Reporter

Craft CMS plugin that injects the [IssueRelay](https://issuerelay.com) feedback widget for logged-in CP users.

## Setup

1. Install the plugin
2. Go to **Settings > Issue Reporter** in the CP
3. Paste your Host URL, Project UUID, API Key, and API Secret from the IssueRelay dashboard
4. Save

The widget automatically appears on all front-end pages for users with CP access. No template changes needed.

## Environment Variables

For production, use env var syntax for credentials:

```
ISSUE_REPORTER_HOST_URL=https://issuerelay.com
ISSUE_REPORTER_PROJECT_UUID=your-uuid
ISSUE_REPORTER_API_SECRET=your-secret
```

Then in plugin settings, enter `$ISSUE_REPORTER_API_SECRET`, etc.

## Requirements

- Craft CMS 5.9+
- PHP 8.2+
