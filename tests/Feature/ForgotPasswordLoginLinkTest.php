<?php

use App\Models\InstanceSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

beforeEach(function () {
    Once::flush();
});

function ensureLoginUserExists(): void
{
    if (User::count() === 0) {
        User::factory()->create();
    }
}

test('login shows active forgot password link when transactional email is enabled', function () {
    InstanceSettings::query()->forceCreate([
        'id' => 0,
        'smtp_enabled' => true,
        'smtp_from_address' => 'hi@localhost.com',
        'smtp_from_name' => 'Coolify',
        'smtp_host' => 'coolify-mail',
        'smtp_port' => 1025,
    ]);
    Once::flush();
    ensureLoginUserExists();

    $this->get('/login')
        ->assertSuccessful()
        ->assertSee(__('auth.forgot_password_link'), false)
        ->assertSee('href="/forgot-password"', false)
        ->assertDontSee(__('auth.forgot_password_disabled_tooltip'), false)
        ->assertDontSee('aria-disabled="true"', false);
});

test('login disables forgot password with tooltip when transactional email is not configured', function () {
    InstanceSettings::query()->forceCreate([
        'id' => 0,
        'smtp_enabled' => false,
        'resend_enabled' => false,
    ]);
    Once::flush();
    ensureLoginUserExists();

    $this->get('/login')
        ->assertSuccessful()
        ->assertSee(__('auth.forgot_password_link'), false)
        ->assertSee(__('auth.forgot_password_disabled_tooltip'), false)
        ->assertSee('aria-disabled="true"', false)
        ->assertDontSee('href="/forgot-password"', false);
});

test('login enables forgot password when only resend is configured', function () {
    InstanceSettings::query()->forceCreate([
        'id' => 0,
        'smtp_enabled' => false,
        'resend_enabled' => true,
        'resend_api_key' => 're_test_key',
    ]);
    Once::flush();
    ensureLoginUserExists();

    $this->get('/login')
        ->assertSuccessful()
        ->assertSee('href="/forgot-password"', false)
        ->assertDontSee('aria-disabled="true"', false);
});
