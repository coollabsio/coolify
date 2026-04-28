<?php

use App\Models\OauthSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('stores the oauth registration restriction setting', function () {
    $setting = OauthSetting::create([
        'provider' => 'github',
        'enabled' => true,
        'allow_registration' => false,
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
    ]);

    expect($setting->allow_registration)->toBeFalse();
    expect($setting->fresh()->allow_registration)->toBeFalse();
});
