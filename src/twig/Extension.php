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

        $settings = IssueReporter::getInstance()->getSettings();

        $hostUrl = rtrim(App::parseEnv($settings->hostUrl), '/');
        if (empty($hostUrl) || str_starts_with($hostUrl, '$')) {
            return '';
        }

        $cacheBust = App::devMode() ? time() : date('Ymd');

        $pageUrl = explode('?', $request->getAbsoluteUrl(), 2)[0];
        $siteHandle = Craft::$app->getSites()->getCurrentSite()->handle;

        $params = [
            'pageUrl' => $pageUrl,
            'siteHandle' => $siteHandle,
        ];
        if ($template !== null) {
            $params['template'] = $template;
        }
        $initUrl = UrlHelper::actionUrl('issue-reporter/widget/init', $params);

        return <<<HTML
        <script src="{$hostUrl}/widget/widget.js?v={$cacheBust}" defer></script>
        <script>
          (function() {
            function initWidget() {
              if (typeof IssueRelay === 'undefined') return;
              fetch('{$initUrl}', {
                credentials: 'same-origin',
                headers: {'Accept': 'application/json'}
              })
              .then(function(r) { return r.status === 200 ? r.json() : null; })
              .then(function(config) {
                if (config) IssueRelay.init(config);
              })
              .catch(function() {});
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
