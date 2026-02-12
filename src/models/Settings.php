<?php

namespace wabisoft\craftissuereporter\models;

use craft\base\Model;

class Settings extends Model
{
    public string $hostUrl = '';
    public string $projectUuid = '';
    public string $apiSecret = '';
    public int $tokenTtl = 3600;
    public bool $autoInject = true;
    public array $allowedUserGroups = [];
    public array $logFiles = [['pattern' => '*.log']];
    public int $maxLogFiles = 5;
    public int $maxLogFileSize = 32;
    public int $maxTotalLogSize = 10000;
    public ?string $primaryColor = null;
    public ?string $primaryHoverColor = null;

    protected function defineRules(): array
    {
        return [
            [['hostUrl', 'projectUuid', 'apiSecret'], 'required'],
            ['hostUrl', 'url', 'defaultScheme' => 'https'],
            ['hostUrl', 'match', 'pattern' => '/^https:\/\//i', 'message' => 'Host URL must use HTTPS.'],
            ['tokenTtl', 'integer', 'min' => 300, 'max' => 86400],
            ['maxLogFiles', 'integer', 'min' => 1, 'max' => 10],
            ['maxLogFileSize', 'integer', 'min' => 8, 'max' => 64],
            ['maxTotalLogSize', 'integer', 'min' => 2000, 'max' => 50000],
            [['primaryColor', 'primaryHoverColor'], 'match', 'pattern' => '/^#[0-9a-fA-F]{6}$/', 'message' => 'Must be a valid hex color (e.g. #1D4ED8).'],
            ['logFiles', function ($attribute) {
                foreach ($this->$attribute as $row) {
                    if (!isset($row['pattern']) || !preg_match('/^[a-zA-Z0-9.*_-]+$/', $row['pattern'])) {
                        $this->addError($attribute, 'Each pattern may only contain filenames and wildcards (*).');
                        return;
                    }
                }
            }],
        ];
    }
}
