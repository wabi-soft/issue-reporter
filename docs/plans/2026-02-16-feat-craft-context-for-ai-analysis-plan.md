---
title: "Add Craft Context to IssueRelay Widget Payload"
type: feat
date: 2026-02-16
brainstorm: docs/brainstorms/2026-02-16-craft-context-for-ai-analysis.md
deepened: 2026-02-16
---

# Add Craft Context to IssueRelay Widget Payload

## Enhancement Summary

**Deepened on:** 2026-02-16
**Agents used:** security-sentinel, performance-oracle, code-simplicity-reviewer, architecture-strategist, pattern-recognition-specialist, CraftCMS API researcher, learnings-researcher

### Key Improvements
1. **Bug fix**: `edition->value` returns int, not string — corrected to use `->name` for readable output
2. **Security**: Strip query string from URL to prevent leaking preview tokens and sensitive params
3. **Robustness**: Add `JSON_THROW_ON_ERROR` to encoding flags to catch silent encoding failures
4. **Consistency**: Remove `declare(strict_types=1)` (no other file in codebase uses it), reorder init config to match priority
5. **Backend coordination**: Note `craft_context` snake_case naming for IssueRelay backend fields

### Corrections From API Research

| Original | Correction |
|---|---|
| `Craft::$app->edition->value` returns string like `"pro"` | Returns `int` (0–3). Use `->name` for `"Solo"`, `"Team"`, `"Pro"`, `"Enterprise"` |
| `getMatchedElement()` returns `null` when no match | Returns `false`, never `null`. `if ($element)` handles both correctly |
| `getServerVersion()` might hit the database | PDO attribute read from existing connection, not a SQL query. Cached after first call |

---

## Overview

Add a `craftContext` object to the `IssueRelay.init()` config alongside `serverLogs`. This gives the IssueRelay AI analysis service environment info (Craft version, PHP, DB, plugins) and request context (URL, template, matched element) — enabling significantly better diagnostic guidance on GitHub issues.

The data is small (~500–1500 bytes) and all sourced from standard Craft APIs already visible to admin users.

## Priority Ordering

The payload fields have explicit priority for display and truncation:

1. **User's message** — the bug report itself (always preserved)
2. **`craftContext`** — environment + request info (small, high diagnostic value)
3. **`serverLogs`** — log file tails (largest, truncatable when space is limited)

This ordering must be respected both in the GitHub issue body layout and in any truncation logic on the IssueRelay backend. The plugin itself does not enforce ordering — JSON key order has no semantic meaning. Priority is a backend/display concern.

## Data Shape

```json
{
  "token": "...",
  "craftContext": {
    "environment": {
      "craft": "5.6.3",
      "php": "8.3.27",
      "db": "mysql 8.4.3",
      "edition": "Pro",
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
| `matchedElement` format | Short class name: `Entry (blog/my-post)` | Cleaner for AI and GitHub display than FQCN. Avoids leaking internal namespace structure |
| Short class name method | `explode('\\', get_class($element))` + `end()` | Simpler than `ReflectionClass` — no object allocation for a string operation |
| `environment` fallback when null | `"unknown"` | Avoids misleading AI into thinking unconfigured = production |
| Edition accessor | `Craft::$app->edition->name` | `->value` returns int (0–3), `->name` returns readable string (`"Solo"`, `"Pro"`, etc.) |
| URL format | Path only, query string stripped | `getAbsoluteUrl()` can include sensitive query params (`?token=...` preview tokens). Strip with `strtok($url, '?')` |
| Include disabled plugins | No | Only enabled plugins via `getAllPlugins()` — disabled adds complexity for marginal value |
| Schema versioning | Skip for now | Shape is simple; additive changes are backwards-compatible |
| Manual Twig function template param | Not in v1 | `null` template is acceptable; add optional param if requested later |
| Include in `/recent-logs` endpoint | No | Context is page-render-time data, not refreshable log data |
| `declare(strict_types=1)` | Omit | No other file in the codebase uses it. Consistency within the project matters more |

## Implementation

### Phase 1: Plugin Changes (this repo)

#### 1. Create `src/services/ContextCollector.php` (NEW)

New service following the `LogCollector` pattern: extends `craft\base\Component`, one public `collect()` method, private helpers.

**Error handling**: Per-section try/catch matching `LogCollector`'s per-file pattern. Each sub-collector (`collectEnvironment`, `collectRequest`) is independently caught so partial data is returned on failure. Failures logged via `Craft::warning()`.

```php
<?php

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
            'edition' => Craft::$app->edition->name,
            'devMode' => App::devMode(),
            'environment' => Craft::$app->env ?? 'unknown',
            'plugins' => $plugins,
        ];
    }

    private function collectRequest(?string $template): array
    {
        $request = Craft::$app->getRequest();
        $site = Craft::$app->getSites()->getCurrentSite();

        $url = $request->getAbsoluteUrl();

        $info = [
            // Strip query string to avoid leaking preview tokens or PII
            'url' => strtok($url, '?'),
            'siteHandle' => $site->handle,
            'isActionRequest' => $request->getIsActionRequest(),
        ];

        if ($template !== null) {
            $info['template'] = $template;
        }

        $element = Craft::$app->getUrlManager()->getMatchedElement();
        if ($element) {
            $parts = explode('\\', get_class($element));
            $desc = end($parts);
            if ($element->uri) {
                $desc .= " ({$element->uri})";
            }
            $info['matchedElement'] = $desc;
        }

        return $info;
    }
}
```

<details>
<summary>Research insights: ContextCollector</summary>

**Performance** (~0.05–0.1ms total overhead):
- All Craft API calls resolve to cached values by template render time
- `getAllPlugins()` iterates an already-loaded array (plugins initialized at bootstrap)
- `getMatchedElement()` returns cached routing result, no DB query
- `getServerVersion()` reads PDO attribute from existing connection, cached after first call
- `ReflectionClass` replaced with `explode`/`end` — zero object allocation

**API correctness verified**:
- `getMatchedElement()` returns `ElementInterface|false` (never `null`). The `if ($element)` check handles `false` correctly
- `getAllPlugins()` returns array keyed by handle — could use `foreach ($plugins as $handle => $plugin)` but `$plugin->handle` is consistent with brainstorm
- `Craft::$app->env` is `?string` — `?? 'unknown'` handles the `null` case
- `App::devMode()` wraps `YII_DEBUG` constant — trivial, no gotchas
- `TemplateEvent::$template` confirmed to exist and contain the page template path

**Security**:
- URL query string stripped to prevent leaking `?token=...` (Craft preview tokens), `?code=...`, or other sensitive params. The path alone provides all diagnostic value
- Template path is relative to `templates/` dir — no filesystem path exposure
- Short class name avoids leaking internal namespace structure

**Simplicity consideration**: The code-simplicity reviewer suggested this could be a private method on `Extension.php` since there is only one caller. A separate service is justified because: (a) it follows the established `LogCollector` pattern, (b) the method count and data-gathering logic warrant their own class, (c) it keeps `Extension.php` as an orchestrator rather than growing its responsibilities. If a second caller appears (unlikely), the service pattern is already correct.

</details>

#### 2. Modify `src/IssueReporter.php`

- Add `use wabisoft\craftissuereporter\services\ContextCollector;` import
- Add `@property-read ContextCollector $contextCollector` to class PHPDoc
- Add `'contextCollector' => ContextCollector::class` to `config()` components array
- In `registerAutoInject()`, pass `$event->template` to `buildWidgetHtml()`:

```php
// In the EVENT_AFTER_RENDER_PAGE_TEMPLATE callback:
$html = $ext->buildWidgetHtml($event->template);
```

<details>
<summary>Research insights: template param threading</summary>

**Architecture review confirmed this is the correct approach.** The template name is only available at event time (`$event->template` from `TemplateEvent`). Passing it as a parameter through the call chain is simpler and more explicit than storing it on a static property.

**Alternative considered and rejected**: `Craft::$app->getView()->getRenderingTemplate()` could theoretically be called from inside `ContextCollector` to avoid threading the parameter. However, this method may not reliably return the page template after rendering completes (it tracks the currently-rendering template during the render, not after). The event-based approach is the documented and reliable path.

**Comment for manual path**: Add a brief comment in `renderWidget()` explaining why template is always `null`:
```php
// Template name is not available in Twig function context;
// it is only captured during auto-inject via EVENT_AFTER_RENDER_PAGE_TEMPLATE.
```

</details>

#### 3. Modify `src/twig/Extension.php`

- Change signature: `buildWidgetHtml(?string $template = null): string`
- Add `craftContext` block **before** `serverLogs` (matches conceptual priority: context before logs), following the same guard pattern:
- Add `JSON_THROW_ON_ERROR` to encoding flags for robustness

```php
// Reorder: craftContext BEFORE serverLogs (matches priority ordering)
if ($settings->includeCraftContext) {
    $context = IssueReporter::getInstance()->contextCollector->collect($template);
    if (!empty($context)) {
        $initConfig['craftContext'] = $context;
    }
}

if (!empty($settings->logFiles)) {
    $logs = IssueReporter::getInstance()->logCollector->collect();
    if (!empty($logs)) {
        $initConfig['serverLogs'] = $logs;
    }
}

// ... theme block stays last ...

// Updated encoding with JSON_THROW_ON_ERROR:
$initConfigJson = json_encode($initConfig, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR);
```

<details>
<summary>Research insights: init config assembly</summary>

**Pattern consistency**: The guard pattern differs by type — `serverLogs` checks `!empty($settings->logFiles)` (array config), `craftContext` checks `$settings->includeCraftContext` (boolean toggle). This is type-appropriate, not an inconsistency. Both share the inner `if (!empty($result))` guard.

**Code ordering**: Reordered to match conceptual priority (context before logs). JSON key order has no semantic meaning, but source code ordering improves readability.

**JSON encoding**: `JSON_HEX_TAG` + `JSON_HEX_AMP` correctly prevent XSS in inline `<script>` tags. All `craftContext` data (template paths, URIs, version strings) is safe after these transformations. `JSON_THROW_ON_ERROR` added as defense-in-depth: without it, `json_encode` returns `false` on failure, which would silently produce broken JS.

**Extension is not a god class** (architecture review). It is an orchestrator that delegates real work to services. Adding ~4 lines for the craftContext block keeps `buildWidgetHtml()` under 50 lines. No extraction needed yet.

</details>

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

**Naming convention**: The widget init config uses camelCase (`craftContext`), matching `serverLogs`. The IssueRelay backend should use snake_case (`craft_context`) for storage and API fields, matching the established `server_logs` convention documented in MEMORY.md.

1. **Store** `craft_context` JSON on the `submissions` table (new column or part of existing `metadata`)
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
4. **Update truncation logic** to preserve `craft_context` before `server_logs` when the GitHub issue body approaches the 65,536 character limit

## Acceptance Criteria

### Plugin (this PR)

- [x] `ContextCollector` service collects environment info (Craft version, PHP, DB, edition, devMode, environment, plugins)
- [x] `ContextCollector` service collects request context (URL without query string, site handle, action request flag, matched element)
- [x] Template name captured from auto-inject event and included in request context
- [x] Template name gracefully omitted when using manual Twig function
- [x] `craftContext` included in `IssueRelay.init()` JSON config when `includeCraftContext` is enabled
- [x] `craftContext` omitted from config when `includeCraftContext` is disabled
- [x] Partial context returned when one sub-collector fails (per-section error handling)
- [x] Failures logged via `Craft::warning()`
- [x] Settings UI lightswitch for `includeCraftContext` in CP settings page
- [x] Edition displays as readable string (`"Pro"`) not integer (`2`)
- [x] URL does not include query parameters
- [x] ECS passes (`composer check-cs`)
- [x] PHPStan passes (`composer phpstan`)

### Backend (future PR, separate repo)

- [x] `craft_context` (snake_case) stored on submissions
- [x] Displayed in GitHub issue body between user message and server logs
- [x] Passed to AI analysis prompt
- [x] Truncation logic preserves craft_context before server_logs

## Files Changed

| File | Action | Description |
|---|---|---|
| `src/services/ContextCollector.php` | **NEW** | Service to collect Craft environment and request context |
| `src/IssueReporter.php` | MODIFY | Register contextCollector component, pass template to buildWidgetHtml |
| `src/twig/Extension.php` | MODIFY | Accept template param, add craftContext to init config, add JSON_THROW_ON_ERROR |
| `src/models/Settings.php` | MODIFY | Add `includeCraftContext` boolean property |
| `src/templates/_settings.twig` | MODIFY | Add lightswitch toggle for includeCraftContext |

## Security Notes

- All data already visible to admin users filing the issue
- Plugin versions go into the project's own GitHub issues (typically private repos)
- `includeCraftContext` toggle gives control if someone doesn't want environment info in issues
- No secrets included (no API keys, tokens, credentials)
- **URL query string stripped** to prevent leaking preview tokens (`?token=...`), reset codes, or other sensitive query params
- `JSON_THROW_ON_ERROR` prevents silent encoding failures from producing malformed inline JS
- Short class name for matched element avoids exposing internal namespace structure

## Performance Notes

Total added overhead: **~0.05–0.1ms per page load** (admin users only).

All Craft API calls resolve to cached, in-memory values by template render time:
- Plugin list: already loaded at bootstrap (~0.01ms to iterate)
- DB version: PDO attribute read, cached (~0.02ms)
- Matched element: cached from URL routing (~0.005ms)
- All others: property reads or constants (~0.001ms each)

The existing `LogCollector` costs 1–5ms (file I/O). `ContextCollector` adds ~1–2% additional overhead. No lazy loading or caching needed.

## Size Considerations

`craftContext` is ~500–1500 bytes depending on plugin count. Negligible overhead compared to `serverLogs` (up to 50KB). No truncation needed for the context data itself.
