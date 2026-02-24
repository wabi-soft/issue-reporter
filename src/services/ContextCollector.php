<?php

namespace wabisoft\craftissuereporter\services;

use Craft;
use craft\base\Component;
use craft\helpers\App;

class ContextCollector extends Component
{
    public function collect(?string $template = null, ?string $pageUrl = null, ?string $siteHandle = null): array
    {
        $context = [];

        try {
            $env = $this->collectEnvironment();
            if (is_array($env) && !empty($env)) {
                $context['environment'] = $env;
            }
        } catch (\Throwable $e) {
            Craft::warning("Failed to collect environment context: {$e->getMessage()}", __METHOD__);
        }

        try {
            $req = $this->collectRequest($template, $pageUrl, $siteHandle);
            if (is_array($req) && !empty($req)) {
                $context['request'] = $req;
            }
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

    private function collectRequest(?string $template, ?string $pageUrl = null, ?string $siteHandle = null): array
    {
        $request = Craft::$app->getRequest();

        // When called from the AJAX init action, use client-reported values
        if ($pageUrl !== null) {
            $info = [
                'url' => explode('?', $pageUrl, 2)[0],
                'siteHandle' => $siteHandle ?? Craft::$app->getSites()->getCurrentSite()->handle,
                'isActionRequest' => false,
            ];

            if ($template !== null) {
                $info['template'] = $template;
            }

            return $info;
        }

        if (!$request->getIsSiteRequest()) {
            return [];
        }

        $site = Craft::$app->getSites()->getCurrentSite();

        $url = $request->getAbsoluteUrl();

        $info = [
            // Strip query string to avoid leaking preview tokens or PII
            'url' => explode('?', $url, 2)[0],
            'siteHandle' => $site->handle,
            'isActionRequest' => $request->getIsActionRequest(),
        ];

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
