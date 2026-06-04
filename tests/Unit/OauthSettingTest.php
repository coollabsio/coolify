<?php

use App\Models\OauthSetting;
use Tests\TestCase;

uses(TestCase::class);

it('requires issuer url client id and client secret for oidc settings', function () {
    $setting = new OauthSetting(['provider' => 'oidc']);
    expect($setting->couldBeEnabled())->toBeFalse();

    $setting->fill([
        'client_id' => 'client-id',
        'client_secret' => 'secret',
        'base_url' => 'https://idp.example.com',
    ]);

    expect($setting->couldBeEnabled())->toBeTrue();
});

it('returns configured scopes and custom login label', function () {
    $setting = new OauthSetting([
        'provider' => 'oidc',
        'scopes' => 'openid email profile groups',
        'custom_label' => 'Login with Okta',
    ]);

    expect($setting->scopeList())->toBe(['openid', 'email', 'profile', 'groups'])
        ->and($setting->loginLabel())->toBe('Login with Okta');
});
