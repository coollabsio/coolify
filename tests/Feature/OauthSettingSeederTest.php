<?php

use App\Models\OauthSetting;
use Database\Seeders\OauthSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds the generic oidc oauth provider', function () {
    $this->seed(OauthSettingSeeder::class);

    expect(OauthSetting::where('provider', 'oidc')->exists())->toBeTrue();
});

it('requires base url before generic oidc can be enabled', function () {
    $oidcSetting = new OauthSetting([
        'provider' => 'oidc',
        'client_id' => 'oidc-client-id',
        'client_secret' => 'oidc-client-secret',
    ]);

    expect($oidcSetting->couldBeEnabled())->toBeFalse();

    $oidcSetting->base_url = 'https://auth.example.com/application/coolify';

    expect($oidcSetting->couldBeEnabled())->toBeTrue();
});
