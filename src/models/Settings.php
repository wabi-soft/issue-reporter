<?php

namespace wabisoft\craftissuereporter\models;

use craft\base\Model;
use craft\behaviors\EnvAttributeParserBehavior;

class Settings extends Model
{
    public string $hostUrl = 'https://issuerelay.com';
    public string $projectUuid = '';
    public string $apiSecret = '';
    public int|string $tokenTtl = 3600;
    public bool|string $autoInject = true;
    public bool|string $includeCraftContext = true;
    public array $allowedUserGroups = [];
    public array $logFiles = [['pattern' => 'web.log']];
    public int|string $maxLogFiles = 5;
    public int|string $maxLogFileSize = 32;
    public int|string $maxTotalLogSize = 10000;
    public ?string $primaryColor = null;
    public ?string $primaryHoverColor = null;

    public function __construct($config = [])
    {
        foreach (['autoInject', 'includeCraftContext'] as $attr) {
            if (($config[$attr] ?? null) === '') {
                unset($config[$attr]);
            }
        }

        parent::__construct($config);
    }

    protected function defineBehaviors(): array
    {
        return [
            'parser' => [
                'class' => EnvAttributeParserBehavior::class,
                'attributes' => [
                    'hostUrl',
                    'projectUuid',
                    'apiSecret',
                    'tokenTtl',
                    'autoInject',
                    'includeCraftContext',
                    'maxLogFiles',
                    'maxLogFileSize',
                    'maxTotalLogSize',
                    'primaryColor',
                    'primaryHoverColor',
                ],
            ],
        ];
    }

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
            [['primaryColor', 'primaryHoverColor'], 'filter', 'filter' => fn($v) => $v ? preg_replace('/\s/', '', $v) : $v],
            ['logFiles', function($attribute) {
                foreach ($this->$attribute as $row) {
                    if (!is_array($row) || !isset($row['pattern']) || !preg_match('/^[a-zA-Z0-9.*_-]+$/', $row['pattern'])) {
                        $this->addError($attribute, 'Each pattern may only contain filenames and wildcards (*).');
                        return;
                    }
                }
            }],
        ];
    }
}
