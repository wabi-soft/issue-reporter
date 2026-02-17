---
title: "Add Craft Context to IssueRelay Widget Payload"
type: feat
date: 2026-02-16
brainstorm: docs/brainstorms/2026-02-16-craft-context-for-ai-analysis.md
---

# Add Craft Context to IssueRelay Widget Payload

## Overview

Add a `craftContext` object to the `IssueRelay.init()` config alongside `serverLogs`. This gives the IssueRelay AI analysis service environment info (Craft version, PHP, DB, plugins) and request context (URL, template, matched element) — enabling significantly better diagnostic guidance on GitHub issues.

The data is small (~500–1500 bytes) and all sourced from standard Craft APIs already visible to admin users.

## Priority Ordering

The payload fields have explicit priority for display and truncation:

1. **User's message** — the bug report itself (always preserved)
2. **`craftContext`** — environment + request info (small, high diagnostic value)
3. **`serverLogs`** — log file tails (largest, truncatable when space is limited)

This ordering must be respected both in the GitHub issue body layout and in any truncation logic on the IssueRelay backend.

## Data Shape

```json
{
  "token": "...",
  "craftContext": {
    "environment": {
      "craft": "5.6.3",
      "php": "8.3.27",
      "db": "mysql 8.4.3",
      "edition": "pro",
      "devMode": true,
      "environment": "dev",
      "plugins": {
        "seomatic": "5.1.5",
        "retour": "5.0.8",
        "issue-reporter": "0.2.0"
      }
    },
    "request": {
      "url": "https://example.com/blog/my-post",
      "template": "blog/_entry",
      "siteHandle": "default",
      "isActionRequest": false,
      "matchedElement": "Entry (blog/my-post)"
    }
  },
  "serverLogs": { "...": "..." }
}
```

### Design Decisions

| Decision | Choice | Rationale |
|---|---|---|
| `matchedElement` format | Short class name: `Entry (blog/my-post)` | Cleaner for AI and GitHub display than FQCN |
| `environment` fallback when null | `"unknown"` | Avoids misleading AI into thinking unconfigured = production |
| Include Craft edition | Yes (`Craft::$app->edition->value`) | Negligible cost, helps AI rule out edition-specific features |
| Include disabled plugins | No | Only enabled plugins via `getAllPlugins()` — disabled adds complexity for marginal value |
| Schema versioning | Skip for now | Shape is simple; additive changes are backwards-compatible |
| Manual Twig function template param | Not in v1 | `null` template is acceptable; add optional param if requested later |
| Include in `/recent-logs` endpoint | No | Context is page-render-time data, not refreshable log data |

## Implementation

### Phase 1: Plugin Changes (this repo)

#### 1. Create `src/services/ContextCollector.php` (NEW)

New service following the `LogCollector` pattern: extends `craft\base\Component`, one public `collect()` method, private helpers.

**Error handling**: Per-section try/catch matching `LogCollector`'s per-file pattern. Each sub-collector (`collectEnvironment`, `collectRequest`) is independently caught so partial data is returned on failure. Failures logged via `Craft::warning()`.

```php
<?php

declare(strict_types=1);

namespace wabisoft\craftissuereporter\services;

use Craft;
use craft\helpers\App;
use craft\base\Component;

class ContextCollector extends Component
{
    public function collect(?string $template = null): array
    {
        $context = [];

        try {
            $context['environment'] = $this->collectEnvironment();
        } catch (\Throwable $e) {
            Craft::warning("Failed to collect environment context: {$e->getMessage()}", __METHOD__);
        }

        try {
            $context['request'] = $this->collectRequest($template);
        } catch (\Throwable $e) {
            Craft::warning("Failed to collect request context: {$e->getMessage()}", __METHOD__);
        }

        return $context;
    }

    private function collectEnvironment(): array
    {
        $plugins = [];
        foreach (Craft::$app->getPlugins()->getAllPlugins() as $plugin) {
            $plugins[$plugin->handle] = $plugin->getVersion();
        }

        return [
            'craft' => Craft::$app->getVersion(),
            'php' => PHP_VERSION,
            'db' => Craft::$app->getDb()->getDriverName() . ' ' . Craft::$app->getDb()->getSchema()->getServerVersion(),
            'edition' => Craft::$app->edition->value,
            'devMode' => App::devMode(),
            'environment' => Craft::$app->env ?? 'unknown',
            'plugins' => $plugins,
        ];
    }

    private function collectRequest(?string $template): array
    {
        $request = Craft::$app->getRequest();
        $site = Craft::$app->getSites()->getCurrentSite();

        $info = [
            'url' => $request->getAbsoluteUrl(),
            'siteHandle' => $site->handle,
            'isActionRequest' => $request->getIsActionRequest(),
        ];

        if ($template !== null) {
            $info['template'] = $template;
        }

        $element = Craft::$app->getUrlManager()->getMatchedElement();
        if ($element) {
            $desc = (new \ReflectionClass($element))->getShortName();
            if ($element->uri) {
                $desc .= " ({$element->uri})";
            }
            $info['matchedElement'] = $desc;
        }

        return $info;
    }
}
```

#### 2. Modify `src/IssueReporter.php`

- Add `use wabisoft\craftissuereporter\services\ContextCollector;` import
- Add `@property-read ContextCollector $contextCollector` to class PHPDoc
- Add `'contextCollector' => ContextCollector::class` to `config()` components array
- In `registerAutoInject()`, pass `$event->template` to `buildWidgetHtml()`:

```php
// In the EVENT_AFTER_RENDER_PAGE_TEMPLATE callback:
$html = $ext->buildWidgetHtml($event->template);
```

#### 3. Modify `src/twig/Extension.php`

- Change signature: `buildWidgetHtml(?string $template = null): string`
- Add `craftContext` block after `serverLogs`, before `theme`, following the same guard pattern:

```php
// After serverLogs block, before theme block:
if ($settings->includeCraftContext) {
    $context = IssueReporter::getInstance()->contextCollector->collect($template);
    if (!empty($context)) {
        $initConfig['craftContext'] = $context;
    }
}
```

The two rendering paths:
- **Auto-inject**: `buildWidgetHtml($event->template)` — template name available
- **Manual Twig function**: `buildWidgetHtml()` — template is `null`, field omitted from payload

#### 4. Modify `src/models/Settings.php`

Add property (no validation rule needed, matching `autoInject` pattern):

```php
public bool $includeCraftContext = true;
```

#### 5. Modify `src/templates/_settings.twig`

Add lightswitch field in the "Widget Settings" section, after the auto-inject toggle:

```twig
{{ forms.lightswitchField({
    label: 'Include Craft Context',
    instructions: 'Send Craft environment info and request context with issue submissions. Helps AI analysis diagnose issues more accurately.',
    id: 'includeCraftContext',
    name: 'includeCraftContext',
    on: settings.includeCraftContext,
}) }}
```

### Phase 2: IssueRelay Backend Changes (separate repo)

> These changes happen in `/Users/dustinwalker/Projects/wabi-soft/issuerelay-backend` and are **out of scope for this PR** but documented here for coordination.

1. **Store** `craftContext` JSON on the `submissions` table (new column or part of existing `metadata`)
2. **Display** in GitHub issue body as a collapsible `<details>` section placed **after the user's message but before server logs** (respecting priority order):

```markdown
<details>
<summary>Craft Environment</summary>

- **Craft:** 5.6.3 (Pro)
- **PHP:** 8.3.27
- **Database:** mysql 8.4.3
- **Dev Mode:** Yes
- **Environment:** dev
- **URL:** https://example.com/blog/my-post
- **Template:** blog/_entry
- **Site:** default
- **Matched Element:** Entry (blog/my-post)

**Plugins:**
- seomatic 5.1.5
- retour 5.0.8
- issue-reporter 0.2.0

</details>
```

3. **Pass** to AI analysis prompt as structured context
4. **Update truncation logic** to preserve `craftContext` before `serverLogs` when the GitHub issue body approaches the 65,536 character limit

## Acceptance Criteria

### Plugin (this PR)

- [ ] `ContextCollector` service collects environment info (Craft version, PHP, DB, edition, devMode, environment, plugins)
- [ ] `ContextCollector` service collects request context (URL, site handle, action request flag, matched element)
- [ ] Template name captured from auto-inject event and included in request context
- [ ] Template name gracefully omitted when using manual Twig function
- [ ] `craftContext` included in `IssueRelay.init()` JSON config when `includeCraftContext` is enabled
- [ ] `craftContext` omitted from config when `includeCraftContext` is disabled
- [ ] Partial context returned when one sub-collector fails (per-section error handling)
- [ ] Failures logged via `Craft::warning()`
- [ ] Settings UI lightswitch for `includeCraftContext` in CP settings page
- [ ] ECS passes (`composer check-cs`)
- [ ] PHPStan passes (`composer phpstan`)

### Backend (future PR, separate repo)

- [ ] `craftContext` stored on submissions
- [ ] Displayed in GitHub issue body between user message and server logs
- [ ] Passed to AI analysis prompt
- [ ] Truncation logic preserves craftContext before serverLogs

## Files Changed

| File | Action | Description |
|---|---|---|
| `src/services/ContextCollector.php` | **NEW** | Service to collect Craft environment and request context |
| `src/IssueReporter.php` | MODIFY | Register contextCollector component, pass template to buildWidgetHtml |
| `src/twig/Extension.php` | MODIFY | Accept template param, add craftContext to init config |
| `src/models/Settings.php` | MODIFY | Add `includeCraftContext` boolean property |
| `src/templates/_settings.twig` | MODIFY | Add lightswitch toggle for includeCraftContext |

## Security Notes

- All data already visible to admin users filing the issue
- Plugin versions go into the project's own GitHub issues (typically private repos)
- `includeCraftContext` toggle gives control if someone doesn't want environment info in issues
- No secrets included (no API keys, tokens, credentials)

## Size Considerations

`craftContext` is ~500–1500 bytes depending on plugin count. Negligible overhead compared to `serverLogs` (up to 50KB). No truncation needed for the context data itself.
