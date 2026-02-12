<?php

namespace wabisoft\craftissuereporter\models;

use craft\base\Model;

class Settings extends Model
{
    public string $hostUrl = '';
    public string $projectUuid = '';
    public string $apiKey = '';
    public string $apiSecret = '';
    public int $tokenTtl = 3600;
    public bool $autoInject = true;
    public array $allowedUserGroups = [];
    public string $logFiles = '*.log';

    protected function defineRules(): array
    {
        return [
            [['hostUrl', 'projectUuid', 'apiSecret'], 'required'],
            ['hostUrl', 'url', 'defaultScheme' => 'https'],
            ['hostUrl', 'match', 'pattern' => '/^https:\/\//i', 'message' => 'Host URL must use HTTPS.'],
            ['tokenTtl', 'integer', 'min' => 300, 'max' => 86400],
            ['logFiles', 'match', 'pattern' => '/^[a-zA-Z0-9.*_,\s-]*$/', 'message' => 'Log files may only contain filenames, wildcards (*), and commas.'],
        ];
    }
}
