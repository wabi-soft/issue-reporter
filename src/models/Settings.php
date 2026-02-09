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

    protected function defineRules(): array
    {
        return [
            [['hostUrl', 'projectUuid', 'apiSecret'], 'required'],
            ['hostUrl', 'url'],
            ['tokenTtl', 'integer', 'min' => 300, 'max' => 86400],
        ];
    }
}
