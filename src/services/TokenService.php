<?php

namespace wabisoft\craftissuereporter\services;

use Craft;
use craft\helpers\App;
use wabisoft\craftissuereporter\IssueReporter;
use yii\base\Component;

class TokenService extends Component
{
    /**
     * Generate an HMAC-signed token for the IssueRelay widget.
     *
     * The token is used by the widget JS for both IssueRelay API auth and the
     * log endpoint. A single unscoped token is intentional — both consumers
     * run in the same browser context with access to the same credentials.
     */
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
            'exp' => time() + (($ttl = (int) App::parseEnv($settings->tokenTtl)) > 0 ? $ttl : 3600),
        ]);

        $encoded = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
        $signature = hash_hmac('sha256', $encoded, $secret);

        return $encoded . '.' . $signature;
    }

    public function validateToken(string $token): bool
    {
        $settings = IssueReporter::getInstance()->getSettings();
        $secret = App::parseEnv($settings->apiSecret);
        $projectUuid = App::parseEnv($settings->projectUuid);

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

        // Decode and check claims
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

        // Verify token was issued for this project
        if (!isset($payload['pid']) || $payload['pid'] !== $projectUuid) {
            return false;
        }

        return true;
    }
}
