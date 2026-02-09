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
