---
title: "Enrich Template Context with Path and Mode"
type: feat
date: 2026-02-16
---

# Enrich Template Context with Path and Mode

## Overview

Add two cheap, one-liner fields to the `request` section of `craftContext`: the resolved filesystem template path and the template mode. These are low-effort additions that give AI analysis slightly more diagnostic context.

## Current Output

```
Template: index.twig
```

## Proposed Output

```
Template: index.twig
Template Path: index.twig
Template Mode: site
```

## Changes

### `src/services/ContextCollector.php`

In `collectRequest()`, after the existing template block (~line 62):

```php
if ($template !== null) {
    $info['template'] = $template;

    $resolvedPath = Craft::$app->getView()->resolveTemplate($template);
    if ($resolvedPath) {
        $basePath = Craft::$app->getView()->getTemplatesPath();
        if (str_starts_with($resolvedPath, $basePath)) {
            $info['templatePath'] = ltrim(substr($resolvedPath, strlen($basePath)), DIRECTORY_SEPARATOR);
        }
    }
}

$info['templateMode'] = Craft::$app->getView()->getTemplateMode();
```

- `resolveTemplate()` returns `string|false` — only include if it resolves and is under the templates root (prevents leaking absolute paths for plugin/module templates)
- `getTemplateMode()` returns `'site'` or `'cp'` — always available, no guard needed
- `templateMode` goes outside the `$template !== null` block since it's useful even when template name is unavailable (manual Twig function path)

### No other runtime files change

- No new settings, no new services, no template/UI changes
- Payload size increase: ~50-80 bytes

## Acceptance Criteria

- [x] `templatePath` included in `craftContext.request` when template resolves
- [x] `templateMode` always included in `craftContext.request`
- [x] ECS passes
- [x] PHPStan level 4 passes

## References

- `src/services/ContextCollector.php:48-77`
- `View::resolveTemplate()` — `vendor/craftcms/cms/src/web/View.php`
- `View::getTemplateMode()` — returns `TEMPLATE_MODE_CP` or `TEMPLATE_MODE_SITE`
