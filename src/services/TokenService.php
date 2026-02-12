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

    public function validateToken(string $token): bool
    {
        $settings = IssueReporter::getInstance()->getSettings();
        $secret = App::parseEnv($settings->apiSecret);

        if (empty($secret) || str_starts_with($secret, '$')) {
            return false;
        }

        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return false;
        }

        [$encoded, $signature] = $parts;

        // Verify HMAC signature (constant-time comparison)
        $expectedSignature = hash_hmac('sha256', $encoded, $secret);
        if (!hash_equals($expectedSignature, $signature)) {
            return false;
        }

        // Decode and check expiration
        $payload = json_decode(
            base64_decode(strtr($encoded, '-_', '+/')),
            true
        );

        if (!is_array($payload) || !isset($payload['exp'])) {
            return false;
        }

        if ($payload['exp'] < time()) {
            Craft::warning('Issue Reporter: Expired token on log endpoint.', __METHOD__);
            return false;
        }

        return true;
    }
}
