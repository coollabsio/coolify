<?php

namespace App\Services\LogDrain;

class NewRelicLogDrain extends FluentBitLogDrain
{
    public function label(): string
    {
        return 'New Relic';
    }

    public function fields(): array
    {
        return [
            'license_key' => ['label' => 'License key', 'rules' => ['string', 'regex:/^[a-zA-Z0-9_\-\.]+$/'], 'required' => true, 'secret' => true],
            'base_uri' => ['label' => 'Endpoint', 'rules' => ['string', 'url'], 'required' => true],
        ];
    }

    protected function output(): string
    {
        return '[OUTPUT]
    Name nrlogs
    Match *
    license_key ${LICENSE_KEY}
    # https://log-api.eu.newrelic.com/log/v1 - EU
    # https://log-api.newrelic.com/log/v1 - US
    base_uri ${BASE_URI}
';
    }

    protected function env(array $config): array
    {
        return [
            'LICENSE_KEY' => (string) ($config['license_key'] ?? ''),
            'BASE_URI' => (string) ($config['base_uri'] ?? ''),
        ];
    }
}
