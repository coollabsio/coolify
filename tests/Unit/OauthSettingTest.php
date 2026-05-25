<?php

use App\Models\OauthSetting;

test('oidc oauth settings require a base url before enabling', function () {
    $setting = new OauthSetting([
        'provider' => 'oidc',
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
    ]);

    expect($setting->couldBeEnabled())->toBeFalse();

    $setting->base_url = 'https://idp.example.com';

    expect($setting->couldBeEnabled())->toBeTrue();
});
