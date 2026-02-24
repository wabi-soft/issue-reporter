<?php

namespace wabisoft\craftissuereporter\controllers;

use Craft;
use craft\helpers\App;
use craft\web\Controller;
use wabisoft\craftissuereporter\IssueReporter;
use yii\web\Response;

class WidgetController extends Controller
{
    protected array|int|bool $allowAnonymous = ['init'];

    public function actionInit(): Response
    {
        $this->requireAcceptsJson();

        $user = Craft::$app->getUser()->getIdentity();
        if (!$user || !$user->can('accessCp')) {
            $this->response->setStatusCode(204);
            $this->response->headers->set('Cache-Control', 'no-store, private');
            return $this->response;
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
                $this->response->setStatusCode(204);
                $this->response->headers->set('Cache-Control', 'no-store, private');
                return $this->response;
            }
        }

        $token = IssueReporter::getInstance()->tokenService->generateToken($user->email);
        if (empty($token)) {
            $this->response->setStatusCode(500);
            $this->response->headers->set('Cache-Control', 'no-store, private');
            return $this->asJson(['error' => 'Plugin not configured']);
        }

        $initConfig = ['token' => $token];

        if (App::parseBooleanEnv($settings->includeCraftContext) ?? true) {
            $request = Craft::$app->getRequest();

            $pageUrl = $request->getQueryParam('pageUrl');
            if (!is_string($pageUrl) || filter_var($pageUrl, FILTER_VALIDATE_URL) === false) {
                $pageUrl = null;
            }

            $template = $request->getQueryParam('template');
            if (!is_string($template) || !preg_match('/^[a-zA-Z0-9_\/.\-]+$/', $template)) {
                $template = null;
            }

            $siteHandle = $request->getQueryParam('siteHandle');
            if (!is_string($siteHandle) || Craft::$app->getSites()->getSiteByHandle($siteHandle) === null) {
                $siteHandle = null;
            }

            $context = IssueReporter::getInstance()->contextCollector->collect($template, $pageUrl, $siteHandle);
            if (!empty($context) && is_array($context)) {
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

        $response = $this->asJson($initConfig);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
