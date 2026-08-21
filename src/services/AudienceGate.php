<?php

namespace wabisoft\craftissuereporter\services;

use Craft;
use craft\base\Component;
use craft\elements\User;
use craft\helpers\App;
use craft\helpers\Session;
use wabisoft\craftissuereporter\IssueReporter;
use wabisoft\craftissuereporter\models\Settings;

class AudienceGate extends Component
{
    /**
     * Whether the current request should receive the widget loader markup.
     *
     * Bails on the cookie check before resolving an identity, so an anonymous
     * request never starts a session and never gets a Set-Cookie back.
     */
    public function allowsInjection(): bool
    {
        $audience = $this->audience();

        if ($audience === Settings::AUDIENCE_NEVER) {
            return false;
        }

        if ($audience === Settings::AUDIENCE_ALWAYS) {
            return true;
        }

        if (!$this->hasAuthCookie()) {
            return false;
        }

        return $this->authorizes(Craft::$app->getUser()->getIdentity());
    }

    /**
     * Whether this user may receive widget config.
     *
     * Ignores injectFor: the injection audience decides who gets markup, never
     * who gets a token.
     */
    public function authorizes(?User $user): bool
    {
        if (!$user || !$user->can('accessCp')) {
            return false;
        }

        $allowedGroups = IssueReporter::getInstance()->getSettings()->allowedUserGroups;
        if (empty($allowedGroups)) {
            return true;
        }

        $allowAdmins = in_array('__admins__', $allowedGroups, true);
        $groupUids = array_filter($allowedGroups, fn($v) => $v !== '__admins__');

        if ($allowAdmins && $user->admin) {
            return true;
        }

        if (empty($groupUids)) {
            return false;
        }

        $userGroupUids = array_map(fn($g) => $g->uid, $user->getGroups());
        $validGroupUids = array_filter(
            $groupUids,
            fn($uid) => Craft::$app->getUserGroups()->getGroupByUid($uid) !== null
        );

        return !empty(array_intersect($validGroupUids, $userGroupUids));
    }

    private function audience(): string
    {
        $audience = App::parseEnv(IssueReporter::getInstance()->getSettings()->injectFor);

        // A value set in config/issue-reporter.php never hits settings validation.
        return in_array($audience, Settings::AUDIENCES, true)
            ? $audience
            : Settings::AUDIENCE_CP_ACCESS;
    }

    private function hasAuthCookie(): bool
    {
        if (Session::exists()) { // Checks for a session id; never starts one.
            return true;
        }

        // Craft enables auto-login, so a remembered user arrives with no session cookie.
        $identityCookie = Craft::$app->getUser()->identityCookie['name'] ?? null;

        return $identityCookie !== null
            && Craft::$app->getRequest()->getCookies()->has($identityCookie);
    }
}
