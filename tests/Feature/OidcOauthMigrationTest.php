<?php

use App\Models\OauthSetting;
use Database\Seeders\OauthSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('inserts a disabled oidc provider row so existing instances see the settings form', function () {
    $oidc = OauthSetting::query()->where('provider', 'oidc')->first();

    expect($oidc)->not->toBeNull()
        ->and($oidc->enabled)->toBeFalse()
        ->and($oidc->couldBeEnabled())->toBeFalse();
});

it('seeds the oidc provider alongside the other oauth providers', function () {
    $this->seed(OauthSettingSeeder::class);

    expect(OauthSetting::query()->where('provider', 'oidc')->exists())->toBeTrue()
        ->and(OauthSetting::query()->pluck('provider'))->toContain('github', 'oidc', 'authentik');
});
