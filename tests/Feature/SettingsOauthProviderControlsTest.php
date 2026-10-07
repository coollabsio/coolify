<?php

use App\Models\OauthSetting;

it('requires the provider-specific fields used by oauth enablement', function (string $provider, array $requiredFields) {
    $complete = [
        'provider' => $provider,
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'tenant' => 'tenant-id',
        'base_url' => 'https://auth.example.com',
    ];

    expect((new OauthSetting($complete))->couldBeEnabled())->toBeTrue();

    foreach ($requiredFields as $field) {
        expect((new OauthSetting([...$complete, $field => null]))->couldBeEnabled())
            ->toBeFalse("{$provider} must require {$field}");
    }
})->with([
    'github' => ['github', ['client_id', 'client_secret']],
    'azure' => ['azure', ['client_id', 'client_secret', 'tenant']],
    'authentik' => ['authentik', ['client_id', 'client_secret', 'base_url']],
    'clerk' => ['clerk', ['client_id', 'client_secret', 'base_url']],
]);
