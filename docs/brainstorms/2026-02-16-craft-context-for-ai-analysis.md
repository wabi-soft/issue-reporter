# Craft Context for AI Bug Analysis

**Date:** 2026-02-16
**Context:** The IssueRelay backend now has AI bug analysis that reviews submissions and posts diagnostic guidance on GitHub issues. The more context the AI has, the better its analysis. The Craft plugin already sends server logs — this plan adds Craft environment info and request context.

## Current State

The plugin sends `serverLogs` (tail of configured log files) inline via `IssueRelay.init()`. The widget includes this in the submission payload, and IssueRelay appends it to the GitHub issue body in a collapsed `<details>` block.

The AI analysis service reads the full issue body (including logs) when generating its diagnostic comment.

## Proposed Addition: `craftContext`

Add a new `craftContext` object to the init config alongside `serverLogs`. This gives the AI:
- What software versions are running (to check known issues)
- What template/route was being viewed when the issue was filed
- What plugins might be involved

### Data Shape

```json
{
  "token": "...",
  "serverLogs": { ... },
  "craftContext": {
    "environment": {
      "craft": "5.6.3",
      "php": "8.3.27",
      "db": "mysql 8.4.3",
      "devMode": true,
      "environment": "dev",
      "plugins": {
        "seomatic": "5.1.5",
        "retour": "5.0.8",
        "issue-reporter": "1.0.0"
      }
    },
    "request": {
      "url": "https://example.com/blog/my-post",
      "template": "blog/_entry",
      "siteHandle": "default",
      "isActionRequest": false,
      "matchedElement": "craft\\elements\\Entry (blog/my-post)"
    }
  }
}
```

## Environment Info

Available via Craft APIs — safe to expose to the project owner (they're already admins):

| Field | Source | Notes |
|-------|--------|-------|
| `craft` | `Craft::$app->getVersion()` | e.g. `5.6.3` |
| `php` | `PHP_VERSION` | e.g. `8.3.27` |
| `db` | `Craft::$app->getDb()->getDriverName()` + server version | e.g. `mysql 8.4.3` |
| `devMode` | `App::devMode()` | boolean |
| `environment` | `Craft::$app->env` or `App::env()` | `dev`, `staging`, `production` |
| `plugins` | `Craft::$app->getPlugins()->getAllPlugins()` | Map of handle → version |

## Request Context

Since the widget only renders for admin users viewing the frontend site:

| Field | Source | Notes |
|-------|--------|-------|
| `url` | `Craft::$app->getRequest()->getAbsoluteUrl()` | Full URL being viewed |
| `template` | `Craft::$app->view->getTemplatesPath()` or resolved template | The Twig template being rendered |
| `siteHandle` | Current site handle from `Craft::$app->getSites()->getCurrentSite()->handle` | Multi-site context |
| `isActionRequest` | `Craft::$app->getRequest()->getIsActionRequest()` | Unlikely true on frontend but useful |
| `matchedElement` | Matched element type + slug from routing | Helps AI understand what content was being viewed |

### Template Path

The tricky one. Craft doesn't have a simple "current template" accessor after rendering. Options:

1. **Capture during `EVENT_BEFORE_RENDER_PAGE_TEMPLATE`** — the `TemplateEvent` has `$event->template`. Store it on the Extension class as a static, similar to `$rendered`.
2. **Use `View::EVENT_AFTER_RENDER_PAGE_TEMPLATE`** — same event we already hook for auto-inject. The `$event->template` is available there.

Option 2 is simplest since we already have the hook. Just capture `$event->template` before building the widget HTML.

### Matched Element

```php
$element = Craft::$app->getUrlManager()->getMatchedElement();
if ($element) {
    $info = get_class($element);
    if (method_exists($element, 'getUriFormat')) {
        $info .= ' (' . $element->uri . ')';
    }
}
```

This gives context like `craft\elements\Entry (blog/my-post)` — enough for the AI to understand what type of page was being viewed.

## Implementation

### File: `src/services/ContextCollector.php` (NEW)

Simple service with one method:

```php
class ContextCollector extends Component
{
    public function collect(?string $template = null): array
    {
        return [
            'environment' => $this->collectEnvironment(),
            'request' => $this->collectRequest($template),
        ];
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
            'devMode' => App::devMode(),
            'environment' => Craft::$app->env ?? 'production',
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

        if ($template) {
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

### File: `src/twig/Extension.php` (MODIFY)

In `buildWidgetHtml()`, after building `serverLogs`, add:

```php
$context = IssueReporter::getInstance()->contextCollector->collect($template);
if (!empty($context)) {
    $initConfig['craftContext'] = $context;
}
```

For the template path, capture it from the auto-inject event:

```php
// In registerAutoInject(), the EVENT_AFTER_RENDER_PAGE_TEMPLATE callback:
$template = $event->template;
// Pass to buildWidgetHtml($template)
```

For the Twig function path (manual `{{ issueRelayWidget() }}`), template isn't available — just pass `null` and omit the field.

### File: `src/IssueReporter.php` (MODIFY)

Register `contextCollector` component:

```php
'components' => [
    'contextCollector' => ContextCollector::class,
    'logCollector' => LogCollector::class,
    'tokenService' => TokenService::class,
],
```

## IssueRelay Backend Changes

On the backend, the `craftContext` data would be:

1. **Stored** as a JSON column on the `submissions` table (or part of a `metadata` column)
2. **Included** in the GitHub issue body in a "Craft Environment" `<details>` section
3. **Passed** to the AI analysis prompt as additional context for diagnosis

The AI prompt could reference it like: "The site is running Craft 5.6.3 with PHP 8.3 on MySQL 8.4. The user was viewing the `blog/_entry` template at `/blog/my-post`."

## Settings

Add a boolean toggle:

```php
public bool $includeCraftContext = true;
```

Enabled by default since users are already project admins. Can be disabled if someone doesn't want environment info in their GitHub issues.

## Security Notes

- All data is already visible to the admin user filing the issue
- Plugin versions could theoretically reveal attack surface — but these are going into private GitHub issues on the project's own repo
- The `includeCraftContext` toggle gives control if needed
- No secrets are included (no API keys, tokens, or credentials)

## Size Considerations

The `craftContext` object is small — roughly 500-1500 bytes depending on number of plugins. No truncation needed. It adds negligible overhead compared to the server logs.

## Implementation Order

1. `ContextCollector` service (new)
2. Register in `IssueReporter.php`
3. Capture template in auto-inject event
4. Pass `craftContext` in `Extension.php`
5. Settings toggle
6. Backend: store + display + feed to AI prompt
