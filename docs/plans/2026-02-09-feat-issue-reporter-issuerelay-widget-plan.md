---
title: "feat: Issue Reporter — IssueRelay Widget Integration"
type: feat
date: 2026-02-09
---

# feat: Issue Reporter — IssueRelay Widget Integration

## Overview

Flesh out the existing `issue-reporter` Craft CMS 5.9 plugin scaffold (`wabisoft/craft-issue-reporter`) to integrate the IssueRelay feedback widget into client sites. The plugin generates server-side HMAC-signed tokens using project API credentials, injects the widget script into front-end pages, and restricts visibility to authenticated CP users.

The plugin runs entirely within the client's Craft CMS site. It makes **zero** runtime HTTP calls — all token generation is local. The widget JS is loaded from the IssueRelay host (`issuerelay.com`).

**Existing scaffold:** `wabisoft\craftissuereporter` namespace, `issue-reporter` handle, `IssueReporter` plugin class — all already in place.

**IssueRelay backend reference:** `/Volumes/Projects/wabi-soft/issuerelay-backend`
- Token validation middleware: `app/Http/Middleware/VerifyWidgetRequest.php`
- Widget JS: `resources/js/widget/widget.js` (Shadow DOM, self-contained)
- API endpoints: `/api/widget/config` (GET), `/api/widget/submissions` (POST)

## Problem Statement

The IssueRelay widget requires an HMAC-signed token to authenticate API requests. The token must be generated server-side (the API secret cannot be exposed client-side). The `issue-reporter` plugin bridges this: it stores credentials, generates fresh tokens per page load, and injects the widget `<script>` + `IssueRelay.init()` call for logged-in Craft CP users.

## Proposed Solution

1. Store IssueRelay credentials (host URL, project UUID, API key, API secret) in plugin settings
2. Provide `{{ issueRelayWidget() }}` Twig function that outputs widget script + signed token
3. Only render for authenticated CP users (with optional user group filtering)
4. Optionally auto-inject on all front-end pages (no Twig call needed)

## Technical Approach

### Architecture

```
┌────────────────────────────────────────────────────────┐
│                Client's Craft CMS Site                  │
│                                                         │
│  ┌────────────────────────────────────────────────────┐ │
│  │         issue-reporter Plugin                       │ │
│  │                                                     │ │
│  │  Settings (models/Settings.php):                    │ │
│  │    - hostUrl, projectUuid, apiKey, apiSecret        │ │
│  │    - tokenTtl, autoInject, allowedUserGroups        │ │
│  │                                                     │ │
│  │  TokenService (services/TokenService.php):          │ │
│  │    - generateToken(email) → base64url.hmac-hex      │ │
│  │                                                     │ │
│  │  Twig Extension (twig/Extension.php):               │ │
│  │    - issueRelayWidget() → <script> + init()         │ │
│  │                                                     │ │
│  │  Auto-inject (EVENT_AFTER_RENDER_PAGE_TEMPLATE):    │ │
│  │    - Appends widget HTML before </body>             │ │
│  └────────────────────────────────────────────────────┘ │
│                          │                               │
│                          ▼                               │
│               Browser loads widget.js from               │
│               IssueRelay host, sends token               │
│               via X-Widget-Token header                   │
└────────────────────────────────────────────────────────┘
```

### Token Contract (Verified Against Backend)

The plugin generates tokens matching `VerifyWidgetRequest` middleware (line 60):

```
Format: <base64url-payload>.<hex-hmac-sha256-signature>

Payload (JSON):
{
  "pid": "project-uuid",       // From settings (projectUuid)
  "email": "user@example.com", // Current Craft user's email
  "iat": 1707000000,           // Unix timestamp (now)
  "exp": 1707003600            // Unix timestamp (now + tokenTtl)
}

Steps:
1. JSON encode payload
2. Base64url encode (strtr +/ → -_, rtrim =)
3. HMAC-SHA256 sign encoded payload with API secret → hex output
4. Concatenate: encodedPayload + "." + hexSignature
```

**Critical:** Signature is **hex-encoded** (`hash_hmac` default). The backend verifies with `hash_equals($expectedSignature, $signature)` where `$expectedSignature = hash_hmac('sha256', $encodedPayload, $apiKey->secret)`.

### Widget Output

```html
<script src="https://issuerelay.com/widget/widget.js" defer></script>
<script>
  (function() {
    function initWidget() {
      if (typeof IssueRelay !== 'undefined') {
        IssueRelay.init({ token: 'GENERATED_TOKEN' });
      }
    }
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', initWidget);
    } else {
      initWidget();
    }
  })();
</script>
```

The `readyState` check handles both in-document and late-injected scripts. The `typeof` guard prevents `ReferenceError` if `widget.js` fails to load (network error, CSP block, etc.). The widget uses Shadow DOM — no CSS conflicts with the host page.

### Directory Structure (files to create/modify)

```
issue-reporter/
  src/
    IssueReporter.php          ← MODIFY (add service registration, twig ext, auto-inject)
    models/
      Settings.php             ← MODIFY (add properties + validation)
    services/
      TokenService.php         ← CREATE
    twig/
      Extension.php            ← CREATE
    templates/
      _settings.twig           ← MODIFY (add form fields)
```

Four files to modify, two to create. Zero new dependencies.

### Implementation Phases

---

#### Phase 1: Settings Model & CP Settings Page

The scaffold already has the plugin class, composer.json, and empty Settings model. Fill them in.

##### Tasks

- [x] Add properties to `src/models/Settings.php`:
  - `hostUrl` (string, default `''`) — IssueRelay host URL
  - `projectUuid` (string, default `''`) — Project UUID
  - `apiKey` (string, default `''`) — Public API key (reference only)
  - `apiSecret` (string, default `''`) — HMAC signing secret
  - `tokenTtl` (int, default `3600`) — Token TTL in seconds
  - `autoInject` (bool, default `true`) — Auto-inject widget
  - `allowedUserGroups` (array, default `[]`) — Restrict to specific groups
- [x] Add `defineRules()` validation: `hostUrl` url format, `projectUuid` required, `apiSecret` required, `tokenTtl` integer min 300 max 86400
- [x] Build `src/templates/_settings.twig` with Craft form macros:
  - `textField` for hostUrl (placeholder: `https://issuerelay.com`)
  - `textField` for projectUuid
  - `textField` for apiKey (with instruction: "For reference only")
  - `passwordField` for apiSecret
  - `textField` type=number for tokenTtl
  - `lightswitchField` for autoInject
  - `checkboxSelectField` for allowedUserGroups (populated from `craft.app.userGroups.getAllGroups()`)

##### `src/models/Settings.php`

```php
<?php

namespace wabisoft\craftissuereporter\models;

use craft\base\Model;

class Settings extends Model
{
    public string $hostUrl = '';
    public string $projectUuid = '';
    public string $apiKey = '';
    public string $apiSecret = '';
    public int $tokenTtl = 3600;
    public bool $autoInject = true;
    public array $allowedUserGroups = [];

    protected function defineRules(): array
    {
        return [
            [['hostUrl', 'projectUuid', 'apiSecret'], 'required'],
            ['hostUrl', 'url'],
            ['tokenTtl', 'integer', 'min' => 300, 'max' => 86400],
        ];
    }
}
```

##### `src/templates/_settings.twig`

```twig
{# @var plugin \wabisoft\craftissuereporter\IssueReporter #}
{# @var settings \wabisoft\craftissuereporter\models\Settings #}

{% import '_includes/forms.twig' as forms %}

{{ forms.textField({
    label: 'IssueRelay Host URL',
    instructions: 'URL of your IssueRelay instance. Supports `$ENV_VAR` syntax.',
    name: 'hostUrl',
    value: settings.hostUrl,
    placeholder: 'https://issuerelay.com',
    required: true,
    errors: settings.getErrors('hostUrl'),
}) }}

{{ forms.textField({
    label: 'Project UUID',
    instructions: 'From the IssueRelay dashboard. Supports `$ENV_VAR` syntax.',
    name: 'projectUuid',
    value: settings.projectUuid,
    required: true,
    errors: settings.getErrors('projectUuid'),
}) }}

{{ forms.textField({
    label: 'API Key',
    instructions: 'Public API key — for your reference only. Not used by the plugin.',
    name: 'apiKey',
    value: settings.apiKey,
    errors: settings.getErrors('apiKey'),
}) }}

{{ forms.passwordField({
    label: 'API Secret',
    instructions: 'HMAC signing secret. Never shared publicly. Supports `$ENV_VAR` syntax.',
    name: 'apiSecret',
    value: settings.apiSecret,
    required: true,
    errors: settings.getErrors('apiSecret'),
}) }}

{{ forms.textField({
    label: 'Token TTL (seconds)',
    instructions: 'How long tokens remain valid. Min 300, max 86400. Default: 3600.',
    name: 'tokenTtl',
    value: settings.tokenTtl,
    type: 'number',
    errors: settings.getErrors('tokenTtl'),
}) }}

{{ forms.lightswitchField({
    label: 'Auto-inject Widget',
    instructions: 'Automatically add the widget to all front-end pages for logged-in users.',
    name: 'autoInject',
    on: settings.autoInject,
}) }}

{% set userGroupOptions = craft.app.userGroups.getAllGroups() | map(g => { label: g.name, value: g.uid }) %}
{{ forms.checkboxSelectField({
    label: 'Allowed User Groups',
    instructions: 'Leave empty to allow all logged-in CP users.',
    name: 'allowedUserGroups',
    values: settings.allowedUserGroups,
    options: userGroupOptions,
}) }}
```

##### Gotchas

- **API Secret in `project.yaml`:** Plaintext unless using `$ISSUE_REPORTER_API_SECRET` env var syntax. Document env vars as the recommended approach.
- **`App::parseEnv()`:** All string credential settings must be parsed at runtime. If result starts with `$`, the env var is undefined.
- **Stale user group UIDs:** If a group is deleted, its UID persists in settings. Filter during authorization. If groups were configured but all are now stale, **deny all** (admin intended restriction). This differs from "no groups configured" which means "allow all CP users."

##### Success Criteria
- Settings page renders in CP
- Settings save and persist
- Validation rejects empty required fields and out-of-range TTL

##### Estimated Effort: Small

---

#### Phase 2: Token Generation Service

##### Tasks

- [x] Create `src/services/TokenService.php` extending `yii\base\Component`
- [x] Implement `generateToken(string $email): string`:
  1. Get settings, parse `apiSecret` and `projectUuid` through `App::parseEnv()`
  2. Guard: return `''` if credentials empty or unresolved (starts with `$`)
  3. Build payload JSON: `pid`, `email`, `iat`, `exp`
  4. Base64url encode → HMAC-SHA256 hex sign → concatenate with `.`
- [x] Register in `IssueReporter::config()` as `tokenService` component
- [x] Add `@property-read TokenService $tokenService` docblock to plugin class

##### `src/services/TokenService.php`

```php
<?php

namespace wabisoft\craftissuereporter\services;

use Craft;
use craft\helpers\App;
use wabisoft\craftissuereporter\IssueReporter;
use yii\base\Component;

class TokenService extends Component
{
    public function generateToken(string $email): string
    {
        $settings = IssueReporter::getInstance()->getSettings();
        $secret = App::parseEnv($settings->apiSecret);
        $projectUuid = App::parseEnv($settings->projectUuid);

        if (empty($secret) || empty($projectUuid) || str_starts_with($secret, '$') || str_starts_with($projectUuid, '$')) {
            Craft::warning('Issue Reporter: Missing or unresolved API credentials.', __METHOD__);
            return '';
        }

        $payload = json_encode([
            'pid' => $projectUuid,
            'email' => strtolower($email),
            'iat' => time(),
            'exp' => time() + $settings->tokenTtl,
        ]);

        $encoded = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
        $signature = hash_hmac('sha256', $encoded, $secret);

        return $encoded . '.' . $signature;
    }
}
```

##### Gotchas

- **Encoding must match exactly.** Backend uses base64url payload + hex signature. Verified against `VerifyWidgetRequest.php:60`: `hash_hmac('sha256', $encodedPayload, $apiKey->secret)` (hex default).
- **Email normalization:** Backend does `strtolower()` on payload email (line 82). Plugin must also lowercase to match.
- **Unresolved env vars:** `App::parseEnv('$UNDEFINED')` returns `'$UNDEFINED'`. Check for `$` prefix, log warning, return empty. The `empty()` check before the `$` prefix check also catches env vars defined as empty strings.

##### Success Criteria
- Token format: `<base64url>.<hex64>`
- Token validates against IssueRelay backend middleware
- Empty credentials return `''` with logged warning

##### Estimated Effort: Small

---

#### Phase 3: Twig Extension & Widget Output

##### Tasks

- [x] Create `src/twig/Extension.php` extending `Twig\Extension\AbstractExtension`
- [x] Register `issueRelayWidget()` Twig function with `['is_safe' => ['html']]`
- [x] Function logic:
  1. Guard: not a site request or is a preview → `''`
  2. Guard: no current user or lacks `accessCp` permission → `''`
  3. Guard: user group restriction (filter stale UIDs) → `''`
  4. Generate token via `TokenService` → `''` if empty
  5. Parse `hostUrl` via `App::parseEnv()` → `''` if empty/unresolved
  6. Return script tags with token
  7. Set static `$rendered = true` for deduplication
- [x] Register Twig extension in `IssueReporter::init()` inside `Craft::$app->onInit()` callback
- [x] Implement auto-inject via `View::EVENT_AFTER_RENDER_PAGE_TEMPLATE`:
  1. Check `autoInject` enabled
  2. Check site request, not preview
  3. Check `$rendered` flag (skip if Twig function already fired)
  4. Run authorization checks
  5. `str_ireplace('</body>', $widgetHtml . '</body>', $output)`

##### `src/twig/Extension.php`

```php
<?php

namespace wabisoft\craftissuereporter\twig;

use Craft;
use craft\helpers\App;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use wabisoft\craftissuereporter\IssueReporter;

class Extension extends AbstractExtension
{
    private static bool $rendered = false;

    public function getFunctions(): array
    {
        return [
            new TwigFunction('issueRelayWidget', [$this, 'renderWidget'], ['is_safe' => ['html']]),
        ];
    }

    public function renderWidget(): string
    {
        if (self::$rendered) {
            return '';
        }

        $html = $this->buildWidgetHtml();
        if ($html !== '') {
            self::$rendered = true;
        }

        return $html;
    }

    public static function wasRendered(): bool
    {
        return self::$rendered;
    }

    public static function markRendered(): void
    {
        self::$rendered = true;
    }

    public function buildWidgetHtml(): string
    {
        $request = Craft::$app->getRequest();
        if (!$request->getIsSiteRequest() || $request->getIsPreview()) {
            return '';
        }

        $user = Craft::$app->getUser()->getIdentity();
        if (!$user || !$user->can('accessCp')) {
            return '';
        }

        $settings = IssueReporter::getInstance()->getSettings();

        if (!empty($settings->allowedUserGroups)) {
            $userGroupUids = array_map(fn($g) => $g->uid, $user->getGroups());
            $validGroupUids = array_filter(
                $settings->allowedUserGroups,
                fn($uid) => Craft::$app->getUserGroups()->getGroupByUid($uid) !== null
            );
            // If groups were configured but all are now stale, deny access
            // (admin intended restriction; deleting groups shouldn't grant everyone access)
            if (empty($validGroupUids) || empty(array_intersect($validGroupUids, $userGroupUids))) {
                return '';
            }
        }

        $token = IssueReporter::getInstance()->tokenService->generateToken($user->email);
        if (empty($token)) {
            return '';
        }

        $hostUrl = rtrim(App::parseEnv($settings->hostUrl), '/');
        if (empty($hostUrl) || str_starts_with($hostUrl, '$')) {
            return '';
        }

        return <<<HTML
        <script src="{$hostUrl}/widget/widget.js" defer></script>
        <script>
          (function() {
            function initWidget() {
              if (typeof IssueRelay !== 'undefined') {
                IssueRelay.init({ token: '{$token}' });
              }
            }
            if (document.readyState === 'loading') {
              document.addEventListener('DOMContentLoaded', initWidget);
            } else {
              initWidget();
            }
          })();
        </script>
        HTML;
    }
}
```

##### Updated `src/IssueReporter.php`

```php
<?php

namespace wabisoft\craftissuereporter;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\events\TemplateEvent;
use craft\web\View;
use wabisoft\craftissuereporter\models\Settings;
use wabisoft\craftissuereporter\services\TokenService;
use wabisoft\craftissuereporter\twig\Extension;
use yii\base\Event;

/**
 * @method static IssueReporter getInstance()
 * @method Settings getSettings()
 * @property-read TokenService $tokenService
 */
class IssueReporter extends Plugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => [
                'tokenService' => TokenService::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        Craft::$app->onInit(function() {
            if (Craft::$app->getRequest()->getIsSiteRequest()) {
                Craft::$app->view->registerTwigExtension(new Extension());
                $this->registerAutoInject();
            }
        });
    }

    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->view->renderTemplate('issue-reporter/_settings.twig', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
        ]);
    }

    private function registerAutoInject(): void
    {
        if (!$this->getSettings()->autoInject) {
            return;
        }

        Event::on(
            View::class,
            View::EVENT_AFTER_RENDER_PAGE_TEMPLATE,
            function(TemplateEvent $event) {
                if (Extension::wasRendered()) {
                    return;
                }

                $ext = new Extension();
                $html = $ext->buildWidgetHtml();
                if (empty($html)) {
                    return;
                }

                Extension::markRendered();
                $event->output = str_ireplace('</body>', $html . '</body>', $event->output);
            }
        );
    }
}
```

##### Twig Usage

**Manual (auto-inject off):**
```twig
{{ issueRelayWidget() }}
```

**Auto-inject (default, auto-inject on):**
No template changes needed.

##### Gotchas

- **`is_safe => ['html']`** prevents Twig from escaping the script tags.
- **Auto-inject uses `str_ireplace`** for case-insensitive `</body>` matching.
- **Deduplication:** Static `$rendered` flag. Both Twig function and auto-inject check/set it.
- **Preview requests:** Excluded via `getIsPreview()`.
- **Template caching:** Widget output is user-specific (email + expiry in token). Must NOT be cached by `{% cache %}` or Blitz.
- **First install:** No settings saved → `autoInject` defaults `true` but token generation returns `''` → no broken HTML injected.
- **Widget JS auto-detects API base:** The widget reads its own `<script src>` to derive the API base URL (see `widget.js:193`). No additional config needed.
- **Widget script load failure:** `typeof IssueRelay` guard in init wrapper prevents `ReferenceError` if `widget.js` fails to load (CSP, network, 404).
- **Stale user group UIDs:** If groups were configured but all deleted, widget is hidden (deny all) — not exposed to everyone. Distinguishes "not configured" (allow all) from "configured but broken" (deny all).
- **Console requests:** `getIsSiteRequest()` returns `false` for console commands — plugin won't run during CLI operations.

##### Success Criteria
- `{{ issueRelayWidget() }}` outputs correct HTML for logged-in CP users
- Returns `''` for guests, unauthorized users, preview, unconfigured settings
- Auto-inject appends before `</body>` when enabled
- No double-rendering if both manual + auto-inject active
- No output on CP pages or Live Preview

##### Estimated Effort: Medium

---

#### Phase 4: Testing & Documentation

##### Tasks

- [ ] Unit test: token format `<base64url>.<hex64>` structure
- [ ] Unit test: token payload contains `pid`, `email`, `iat`, `exp`
- [ ] Unit test: token expiry = `time() + tokenTtl`
- [ ] Unit test: empty credentials → empty string + warning
- [ ] Unit test: unresolved env var (`$UNDEFINED`) → empty string
- [ ] Unit test: widget HTML contains host URL and token
- [ ] Unit test: widget not rendered for guests
- [ ] Unit test: widget not rendered for users outside allowed groups
- [ ] Unit test: widget not rendered with unconfigured settings
- [ ] Unit test: widget not rendered on preview requests
- [ ] Unit test: stale user group UIDs filtered correctly
- [ ] Integration test: token from plugin validates against IssueRelay backend
- [ ] Update README with installation, configuration, env var usage
- [ ] Document CSP requirements (`script-src` for IssueRelay host)
- [ ] Document template caching incompatibility
- [ ] Document that Craft user emails must be on IssueRelay email allowlist
- [ ] Document that site domain must be on IssueRelay domain allowlist

##### Success Criteria
- All unit tests pass
- README covers full setup flow
- Token interop verified with IssueRelay backend

##### Estimated Effort: Small

---

## Acceptance Criteria

### Functional Requirements

- [ ] Plugin installs on Craft CMS 5.9+ via Composer
- [ ] CP settings page stores host URL, project UUID, API key, API secret, TTL, auto-inject, user groups
- [ ] `{{ issueRelayWidget() }}` outputs widget script + signed token for authorized users
- [ ] Auto-inject mode works without template changes
- [ ] Widget only visible to logged-in CP users with `accessCp` permission
- [ ] Widget visibility filterable by user groups
- [ ] Generated tokens validate against IssueRelay `VerifyWidgetRequest` middleware
- [ ] Environment variables supported (`$ENV_VAR` syntax) for credential fields
- [ ] No output when settings unconfigured or env vars undefined
- [ ] No output on CP pages, preview requests, or for unauthorized users

### Non-Functional Requirements

- [ ] < 1ms page render overhead (single `hash_hmac` call, no HTTP requests)
- [ ] No database queries (Craft caches plugin settings)
- [ ] Zero JS dependencies (widget loaded from IssueRelay host)
- [ ] PHP 8.2+, Craft CMS 5.9+

### Quality Gates

- [ ] Unit tests for token generation, authorization, edge cases
- [ ] README with setup, env vars, caching caveats, CSP docs
- [ ] Token format verified against backend middleware source

## Dependencies & Prerequisites

- IssueRelay backend deployed at `issuerelay.com` (widget JS served, API live)
- Project created in IssueRelay with API key generated
- Client domain on IssueRelay project's allowed domains list
- Client user emails on IssueRelay project's allowed emails list
- Craft CMS 5.9+ site for development

## Risk Analysis & Mitigation

| Risk | Impact | Mitigation |
|------|--------|------------|
| API secret in `project.yaml` | Secret in version control | Document env var usage as required for production |
| Token format mismatch | Silent auth failure | Unit test format; integration test against backend |
| Craft email not on allowlist | Widget loads, submissions rejected | Document in README |
| IssueRelay host down | Widget JS fails to load | `defer` — page loads normally, widget absent |
| CSP blocks widget | Script blocked | Document CSP directives in README |
| Auto-inject in wrong place | Broken layout | `str_ireplace('</body>')` + manual Twig fallback |
| Template caching | Stale/wrong tokens served | Document: widget output must not be cached |
| Plugin installed, no settings | Broken HTML on all pages | Guard clauses return `''` when settings empty |
| Unresolved env vars | Token signed with literal `$VAR` | Check `$` prefix, log warning, return `''` |
| Stale user group UIDs | Widget disappears for all users | Filter invalid UIDs; empty valid list = allow all |

## Design Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Naming | `issue-reporter` handle, `IssueReporter` class | Existing scaffold; "reports issues to the relay" |
| Token signing | Hex HMAC-SHA256 | Matches `VerifyWidgetRequest.php:60` |
| Widget injection | `str_ireplace('</body>')` | Standard Craft pattern |
| Init wrapper | `document.readyState` check | Handles pre- and post-DOMContentLoaded |
| Settings storage | Craft project config + env vars | Standard Craft pattern |
| User authorization | `can('accessCp')` + optional group filter | Only CMS admins see the widget |
| Preview exclusion | `getIsPreview()` | Widget in Live Preview is confusing |
| Caching | Explicitly incompatible | Token is user-specific + time-bound |
| Twig extension registration | Inside `onInit()` callback | Avoids conflicts with other plugins per Craft docs |

## Future Considerations

Deferred:

- Widget position configuration (hardcoded bottom-right in widget JS)
- Multi-project support (one Craft site → multiple IssueRelay projects)
- CP section for viewing submissions (use IssueRelay dashboard)
- Health check / "Test Connection" button
- Per-site configuration (Craft multi-site)
- Widget JS version pinning (`widget.js?v=X`)
- SRI hash on script tag

## References

### IssueRelay Backend (token contract source of truth)

- **Repo:** `/Volumes/Projects/wabi-soft/issuerelay-backend`
- Token validation: `app/Http/Middleware/VerifyWidgetRequest.php` (lines 25-63)
- Widget JS: `resources/js/widget/widget.js` (Shadow DOM, auto-detects API base)
- API routes: `routes/api.php`

### Craft CMS 5 Plugin Development

- [Plugin Guide](https://craftcms.com/docs/5.x/extend/plugin-guide.html)
- [Plugin Settings](https://craftcms.com/docs/5.x/extend/plugin-settings.html)
- [Extending Twig](https://craftcms.com/docs/5.x/extend/extending-twig.html)
- [Services](https://craftcms.com/docs/5.x/extend/services.html)

### Conventions (from sibling plugins)

- Service registration via `config()` static method (see `craft-mimi/src/Mimi.php:43`)
- Twig extension registration inside `onInit()` callback (see `craft-mimi/src/Mimi.php:64`)
- Settings model with `defineRules()` validation (see `craft-mimi/src/models/Settings.php:49`)
- Env var support via `App::parseEnv()` / `App::parseBooleanEnv()`
