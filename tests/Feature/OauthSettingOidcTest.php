<?php

use App\Models\OauthSetting;

it('requires base_url for oidc to be enabled', function () {
    $setting = new OauthSetting([
        'provider' => 'oidc',
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
    ]);

    expect($setting->couldBeEnabled())->toBeFalse();

    $setting->base_url = 'https://idp.example.com';

    expect($setting->couldBeEnabled())->toBeTrue();
});
