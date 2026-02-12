<?php

namespace wabisoft\craftissuereporter\controllers;

use Craft;
use craft\web\Controller;
use wabisoft\craftissuereporter\IssueReporter;
use yii\web\Response;

class LogController extends Controller
{
    protected array|int|bool $allowAnonymous = ['recent-logs'];

    public function actionRecentLogs(): Response
    {
        $this->requireAcceptsJson();

        $authHeader = Craft::$app->getRequest()->getHeaders()->get('Authorization');
        $token = null;
        if ($authHeader && preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            $token = $matches[1];
        }

        if (!$token || !IssueReporter::getInstance()->tokenService->validateToken($token)) {
            Craft::$app->getResponse()->setStatusCode(401);
            return $this->asJson(['error' => 'Unauthorized']);
        }

        $logs = IssueReporter::getInstance()->logCollector->collect();

        $response = $this->asJson(['logs' => $logs ?: new \stdClass()]);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
