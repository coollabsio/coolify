<?php

use App\Models\OauthSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns 404 for unknown oauth provider redirect', function () {
    $response = $this->get('/auth/not-a-real-provider/redirect');

    $response->assertNotFound();
});

it('returns 403 when oauth provider exists but is disabled', function () {
    OauthSetting::create([
        'provider' => 'github',
        'enabled' => false,
        'client_id' => 'id',
        'client_secret' => 'secret',
    ]);

    $response = $this->get('/auth/github/redirect');

    $response->assertForbidden();
});
