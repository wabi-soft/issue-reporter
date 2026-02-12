<?php

namespace wabisoft\craftissuereporter\twig;

use Craft;
use craft\helpers\App;
use craft\helpers\UrlHelper;
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

        $cacheBust = App::devMode() ? time() : date('Ymd');

        $initConfig = ['token' => $token];
        if (trim($settings->logFiles) !== '') {
            $initConfig['logsEndpoint'] = UrlHelper::actionUrl('issue-reporter/logs/recent-logs');
        }
        $initConfigJson = json_encode($initConfig, JSON_UNESCAPED_SLASHES);

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
