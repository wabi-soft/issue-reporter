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

        // Template name is only available via auto-inject (EVENT_AFTER_RENDER_PAGE_TEMPLATE).
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

    public function buildWidgetHtml(?string $template = null): string
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
            $allowAdmins = in_array('__admins__', $settings->allowedUserGroups, true);
            $groupUids = array_filter($settings->allowedUserGroups, fn($v) => $v !== '__admins__');

            $allowed = $allowAdmins && $user->admin;

            if (!$allowed && !empty($groupUids)) {
                $userGroupUids = array_map(fn($g) => $g->uid, $user->getGroups());
                $validGroupUids = array_filter(
                    $groupUids,
                    fn($uid) => Craft::$app->getUserGroups()->getGroupByUid($uid) !== null
                );
                $allowed = !empty(array_intersect($validGroupUids, $userGroupUids));
            }

            if (!$allowed) {
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

        $cacheBust = App::devMode() ? time() : date('Ymd');

        $initConfig = ['token' => $token];

        if (App::parseBooleanEnv($settings->includeCraftContext) ?? true) {
            $context = IssueReporter::getInstance()->contextCollector->collect($template);
            if (!empty($context) && is_array($context)) {
                // Ensure each sub-key is an array/object, never a scalar
                $sanitized = array_filter($context, fn($v) => is_array($v));
                if (!empty($sanitized)) {
                    $initConfig['craftContext'] = $sanitized;
                }
            }
        }

        if (!empty($settings->logFiles)) {
            $logs = IssueReporter::getInstance()->logCollector->collect();
            if (!empty($logs)) {
                $initConfig['serverLogs'] = $logs;
            }
        }

        $primaryColor = App::parseEnv($settings->primaryColor);
        $primaryHoverColor = App::parseEnv($settings->primaryHoverColor);

        $theme = array_filter([
            'primary' => $primaryColor && !str_starts_with($primaryColor, '$') ? '#' . ltrim($primaryColor, '#') : null,
            'primaryHover' => $primaryHoverColor && !str_starts_with($primaryHoverColor, '$') ? '#' . ltrim($primaryHoverColor, '#') : null,
        ]);
        if (!empty($theme)) {
            $initConfig['theme'] = $theme;
        }

        try {
            $initConfigJson = json_encode($initConfig, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            Craft::warning("Failed to encode widget config: {$e->getMessage()}", __METHOD__);
            return '';
        }

        return <<<HTML
        <script src="{$hostUrl}/widget/widget.js?v={$cacheBust}" defer></script>
        <script>
          (function() {
            function initWidget() {
              if (typeof IssueRelay !== 'undefined') {
                IssueRelay.init({$initConfigJson});
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
