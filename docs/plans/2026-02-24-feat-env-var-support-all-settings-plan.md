---
title: "feat: Add full environment variable and config file support for all settings"
type: feat
date: 2026-02-24
---

# Add Full Environment Variable and Config File Support

## Overview

Currently only 3 of 12 settings (`hostUrl`, `projectUuid`, `apiSecret`) support `$ENV_VAR` syntax via `App::parseEnv()`. The remaining settings (integers, booleans, colors, arrays) have no env var support and no `config/issue-reporter.php` documentation. This makes it difficult to manage the plugin across environments (dev/staging/production) without manually configuring each one.

This plan adds env var support to all settings, with a pragmatic split: string and integer settings get `autosuggestField` in the CP for env var autocomplete, while booleans, colors, and arrays are overridable via the config file only (preserving their specialized UI controls).

## Problem Statement

1. **Incomplete `parseEnv()` coverage** — Only `hostUrl`, `apiSecret`, `projectUuid` are parsed. Settings like `tokenTtl`, `maxLogFiles`, colors, etc. ignore env var references.
2. **No config file documentation** — Craft automatically supports `config/issue-reporter.php` but the plugin doesn't document it or show override indicators in the CP.
3. **Type mismatch** — Model properties are strictly typed (`public int $tokenTtl`), so storing `$TOKEN_TTL` would throw a `TypeError`.
4. **Validation rejects env vars** — The URL validator, integer validators, and HTTPS regex all reject raw `$ENV_VAR` strings, preventing save.
5. **No autosuggest** — The settings template uses `textField` instead of `autosuggestField`, so there's no env var autocomplete.

## Proposed Solution

### Architecture: Typed Accessor Pattern

Add accessor methods on the `Settings` model that handle `parseEnv()` + type casting + fallback to defaults. Consumers call accessors instead of reading raw properties.

```php
// Settings.php

public function getHostUrl(): string
{
    return $this->parseString('hostUrl');
}

public function getTokenTtl(): int
{
    return $this->parseInt('tokenTtl', 300, 86400);
}

public function getAutoInject(): bool
{
    return $this->parseBool('autoInject');
}

private function parseString(string $prop): string
{
    $raw = $this->$prop;
    if (!is_string($raw)) {
        return (string)$raw;
    }
    $parsed = App::parseEnv($raw);
    if (str_starts_with($parsed, '$')) {
        Craft::warning("Issue Reporter: Unresolved env var in {$prop}.", __METHOD__);
        return '';
    }
    return $parsed;
}

private function parseInt(string $prop, int $min, int $max): int
{
    $raw = $this->$prop;
    if (is_int($raw)) {
        return $raw;
    }
    $parsed = App::parseEnv((string)$raw);
    if (!is_numeric($parsed) || str_starts_with($parsed, '$')) {
        Craft::warning("Issue Reporter: Invalid or unresolved env var in {$prop}, using default.", __METHOD__);
        return (new \ReflectionProperty($this, $prop))->getDefaultValue();
    }
    $value = (int)$parsed;
    if ($value < $min || $value > $max) {
        Craft::warning("Issue Reporter: {$prop} value {$value} out of range [{$min}, {$max}], using default.", __METHOD__);
        return (new \ReflectionProperty($this, $prop))->getDefaultValue();
    }
    return $value;
}

private function parseBool(string $prop): bool
{
    $raw = $this->$prop;
    if (is_bool($raw)) {
        return $raw;
    }
    $parsed = App::parseEnv((string)$raw);
    if (str_starts_with($parsed, '$')) {
        Craft::warning("Issue Reporter: Unresolved env var in {$prop}, using default.", __METHOD__);
        return (new \ReflectionProperty($this, $prop))->getDefaultValue();
    }
    return filter_var($parsed, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
        ?? (new \ReflectionProperty($this, $prop))->getDefaultValue();
}
```

### Settings by Override Method

| Setting | CP autosuggest | Config file | Type |
|---------|:-:|:-:|------|
| `hostUrl` | ✅ | ✅ | string |
| `projectUuid` | ✅ | ✅ | string |
| `apiSecret` | ✅ | ✅ | string |
| `tokenTtl` | ✅ | ✅ | int |
| `maxLogFiles` | ✅ | ✅ | int |
| `maxLogFileSize` | ✅ | ✅ | int |
| `maxTotalLogSize` | ✅ | ✅ | int |
| `primaryColor` | ❌ (color picker) | ✅ | string |
| `primaryHoverColor` | ❌ (color picker) | ✅ | string |
| `autoInject` | ❌ (lightswitch) | ✅ | bool |
| `includeCraftContext` | ❌ (lightswitch) | ✅ | bool |
| `allowedUserGroups` | ❌ (checkboxes) | ✅ | array |
| `logFiles` | ❌ (editable table) | ✅ | array |

## Technical Considerations

### Model Property Types

Properties that can hold env var strings need union types:

```php
public string|int $tokenTtl = 3600;
public string|bool $autoInject = true;
public string|bool $includeCraftContext = true;
public string|int $maxLogFiles = 5;
public string|int $maxLogFileSize = 32;
public string|int $maxTotalLogSize = 10000;
```

String properties (`hostUrl`, `projectUuid`, `apiSecret`, `primaryColor`, `primaryHoverColor`) already accept env var strings natively. Array properties (`logFiles`, `allowedUserGroups`) stay as `array` — they're only overridable via config file which passes native PHP arrays.

### Conditional Validation

Skip type-specific validators when the value is an env var reference:

```php
// Settings.php helper
private static function isEnvRef(mixed $value): bool
{
    return is_string($value) && str_starts_with($value, '$');
}

// In defineRules():
['hostUrl', 'url', 'when' => fn() => !self::isEnvRef($this->hostUrl)],
['hostUrl', 'match', 'pattern' => '/^https:\/\//i', 'when' => fn() => !self::isEnvRef($this->hostUrl)],
['tokenTtl', 'integer', 'min' => 300, 'max' => 86400, 'when' => fn() => !self::isEnvRef($this->tokenTtl)],
// ... same pattern for all type-specific rules
```

The `required` rule still applies (the `$ENV_VAR` string is non-empty).

### Config File Override Indicators

Check for `config/issue-reporter.php` in the settings template and mark overridden fields as disabled:

```twig
{% set configOverrides = craft.app.config.getConfigFromFile('issue-reporter') %}

{{ forms.autosuggestField({
    label: 'Host URL',
    name: 'hostUrl',
    suggestEnvVars: true,
    value: settings.hostUrl,
    disabled: configOverrides.hostUrl is defined,
    warning: configOverrides.hostUrl is defined ? 'Overridden by config/issue-reporter.php' : null,
}) }}
```

### Runtime Failure Guards

Every parsed value is validated at runtime in the accessor methods. Unresolved env vars (`str_starts_with($parsed, '$')`) and out-of-range values log a warning and fall back to the property's default value. This prevents:

- PHP `TypeError` from assigning strings to int operations
- Silent misbehavior from `(int)"$UNSET_VAR"` = 0
- `(bool)"$UNSET_VAR"` = true (non-empty string is truthy)

### `apiSecret` Field — Password vs Autosuggest

The `apiSecret` currently uses `passwordField`. Switch to `autosuggestField` but detect whether the stored value is an env var reference:

- If value starts with `$` → show as plaintext autosuggest (it's just a var name, not sensitive)
- If value is a literal secret → use `passwordField` behavior

Simplest approach: always use `autosuggestField` with `suggestEnvVars: true`. The stored `$ENV_VAR` name is not sensitive. If a user stores a literal secret, the CP field shows it in plaintext — but Craft's own convention (e.g., email transport password) does this too when using autosuggest.

## Acceptance Criteria

- [x] All string/int settings accept `$ENV_VAR` syntax in CP with autosuggest autocomplete
- [x] Boolean, color, and array settings are overridable via `config/issue-reporter.php`
- [x] Validation passes when env var references are entered in CP fields (via `EnvAttributeParserBehavior`)
- [x] Unresolved env vars at runtime handled by existing guards + `EnvAttributeParserBehavior`
- [x] Config file overrides show a warning/disabled state in the CP settings UI
- [x] All consumption sites use `App::parseEnv()` / `App::parseBooleanEnv()` at point of use (Craft-native pattern)
- [x] Existing literal values continue to work unchanged (backward compatible)
- [x] README documents `config/issue-reporter.php` usage with examples

## Files to Modify

### `src/models/Settings.php`
- Add union types to int/bool properties
- Add `isEnvRef()` helper
- Update `defineRules()` with conditional validation
- Add typed accessor methods: `getHostUrl()`, `getProjectUuid()`, `getApiSecret()`, `getTokenTtl()`, `getAutoInject()`, `getIncludeCraftContext()`, `getMaxLogFiles()`, `getMaxLogFileSize()`, `getMaxTotalLogSize()`, `getPrimaryColor()`, `getPrimaryHoverColor()`
- Add private parse helpers: `parseString()`, `parseInt()`, `parseBool()`

### `src/templates/_settings.twig`
- Replace `textField` with `autosuggestField` (+ `suggestEnvVars: true`) for: `hostUrl`, `projectUuid`, `tokenTtl`, `maxLogFiles`, `maxLogFileSize`, `maxTotalLogSize`
- Replace `passwordField` with `autosuggestField` for `apiSecret`
- Add config file override detection and disabled/warning state for all fields
- Keep `lightswitchField`, `colorField`, `editableTableField`, `checkboxSelectField` as-is

### `src/twig/Extension.php`
- Replace `$settings->hostUrl` → `$settings->getHostUrl()`
- Replace `$settings->includeCraftContext` → `$settings->getIncludeCraftContext()`
- Replace `$settings->autoInject` (via `IssueReporter.php`) → `$settings->getAutoInject()`
- Replace `$settings->logFiles` → direct access (array, no parseEnv needed)
- Replace `$settings->primaryColor` / `primaryHoverColor` → `$settings->getPrimaryColor()` / `getPrimaryHoverColor()`
- Remove manual `App::parseEnv()` and `str_starts_with` guards (now in accessors)

### `src/services/TokenService.php`
- Replace `App::parseEnv($settings->apiSecret)` → `$settings->getApiSecret()`
- Replace `App::parseEnv($settings->projectUuid)` → `$settings->getProjectUuid()`
- Replace `$settings->tokenTtl` → `$settings->getTokenTtl()`
- Remove manual `str_starts_with` guards (now in accessors)

### `src/services/LogCollector.php`
- Replace `$settings->maxLogFiles` → `$settings->getMaxLogFiles()`
- Replace `$settings->maxLogFileSize` → `$settings->getMaxLogFileSize()`
- Replace `$settings->maxTotalLogSize` → `$settings->getMaxTotalLogSize()`

### `src/IssueReporter.php`
- Replace `$this->getSettings()->autoInject` → `$this->getSettings()->getAutoInject()`

### `README.md`
- Add config file documentation section with example `config/issue-reporter.php`

## Dependencies & Risks

- **Backward compatible** — No schema version bump needed. Existing literal values work unchanged through the accessor methods.
- **Craft 5 compatibility** — `autosuggestField` with `suggestEnvVars` is supported in Craft 5. Config file overrides are built into `craft\base\Plugin`.
- **Risk: `ReflectionProperty::getDefaultValue()`** — Used for fallback defaults in parse helpers. Available in PHP 8.0+. Alternatively, define a `const DEFAULTS` array.
- **Risk: Union types in model** — Yii2 model attribute handling should be tested with `string|int` and `string|bool` to ensure settings save/load correctly from project config.

## References

- `src/models/Settings.php` — Current settings model
- `src/twig/Extension.php:86-89` — Existing parseEnv + guard pattern
- `src/services/TokenService.php:22-25` — Existing parseEnv + guard pattern
- `src/templates/_settings.twig` — Current settings template
- Craft 5 `autosuggestField` — supports `suggestEnvVars: true` for env var autocomplete
- Craft 5 config file overrides — automatic via `config/{plugin-handle}.php`
