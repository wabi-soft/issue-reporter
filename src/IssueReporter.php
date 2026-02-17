<?php

namespace wabisoft\craftissuereporter;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\events\TemplateEvent;
use craft\web\View;
use wabisoft\craftissuereporter\models\Settings;
use wabisoft\craftissuereporter\services\ContextCollector;
use wabisoft\craftissuereporter\services\LogCollector;
use wabisoft\craftissuereporter\services\TokenService;
use wabisoft\craftissuereporter\twig\Extension;
use yii\base\Event;

/**
 * @method static IssueReporter getInstance()
 * @method Settings getSettings()
 * @property-read ContextCollector $contextCollector
 * @property-read LogCollector $logCollector
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
                'contextCollector' => ContextCollector::class,
                'logCollector' => LogCollector::class,
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
                $html = $ext->buildWidgetHtml($event->template);
                if (empty($html)) {
                    return;
                }

                Extension::markRendered();
                $event->output = str_ireplace('</body>', $html . '</body>', $event->output);
            }
        );
    }
}
