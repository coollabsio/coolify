<?php

use App\Models\InstanceSettings;
use App\Models\User;
use App\Notifications\TransactionalEmails\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::forget('instance_settings_fqdn_host');
    Once::flush();
});

function callResetUrl(ResetPassword $notification, $notifiable): string
{
    $method = new ReflectionMethod($notification, 'resetUrl');

    return $method->invoke($notification, $notifiable);
}

it('generates reset URL using configured FQDN, not request host', function () {
    InstanceSettings::updateOrCreate(
        ['id' => 0],
        ['fqdn' => 'https://coolify.example.com', 'public_ipv4' => '65.21.3.91']
    );
    Once::flush();

    $user = User::factory()->create();
    $notification = new ResetPassword('test-token-abc', isTransactionalEmail: false);

    $url = callResetUrl($notification, $user);

    expect($url)
        ->toStartWith('https://coolify.example.com/')
        ->toContain('test-token-abc')
        ->toContain(urlencode($user->email))
        ->not->toContain('localhost');
});

it('generates reset URL using public IP when no FQDN is configured', function () {
    InstanceSettings::updateOrCreate(
        ['id' => 0],
        ['fqdn' => null, 'public_ipv4' => '65.21.3.91']
    );
    Once::flush();

    $user = User::factory()->create();
    $notification = new ResetPassword('test-token-abc', isTransactionalEmail: false);

    $url = callResetUrl($notification, $user);

    expect($url)
        ->toContain('65.21.3.91')
        ->toContain('test-token-abc')
        ->not->toContain('evil.com');
});

it('is immune to X-Forwarded-Host header poisoning when FQDN is set', function () {
    InstanceSettings::updateOrCreate(
        ['id' => 0],
        ['fqdn' => 'https://coolify.example.com', 'public_ipv4' => '65.21.3.91']
    );
    Once::flush();

    // Simulate a request with a spoofed X-Forwarded-Host header
    $user = User::factory()->create();

    $this->withHeaders([
        'X-Forwarded-Host' => 'evil.com',
    ])->get('/');

    $notification = new ResetPassword('poisoned-token', isTransactionalEmail: false);
    $url = callResetUrl($notification, $user);

    expect($url)
        ->toStartWith('https://coolify.example.com/')
        ->toContain('poisoned-token')
        ->not->toContain('evil.com');
});

it('is immune to X-Forwarded-Host header poisoning when using IP only', function () {
    InstanceSettings::updateOrCreate(
        ['id' => 0],
        ['fqdn' => null, 'public_ipv4' => '65.21.3.91']
    );
    Once::flush();

    $user = User::factory()->create();

    $this->withHeaders([
        'X-Forwarded-Host' => 'evil.com',
    ])->get('/');

    $notification = new ResetPassword('poisoned-token', isTransactionalEmail: false);
    $url = callResetUrl($notification, $user);

    expect($url)
        ->toContain('65.21.3.91')
        ->toContain('poisoned-token')
        ->not->toContain('evil.com');
});

it('generates reset URL with bracketed IPv6 when no FQDN is configured', function () {
    InstanceSettings::updateOrCreate(
        ['id' => 0],
        ['fqdn' => null, 'public_ipv4' => null, 'public_ipv6' => '2001:db8::1']
    );
    Once::flush();

    $user = User::factory()->create();
    $notification = new ResetPassword('ipv6-token', isTransactionalEmail: false);

    $url = callResetUrl($notification, $user);

    expect($url)
        ->toContain('[2001:db8::1]')
        ->toContain('ipv6-token')
        ->toContain(urlencode($user->email));
});

it('is immune to X-Forwarded-Host header poisoning when using IPv6 only', function () {
    InstanceSettings::updateOrCreate(
        ['id' => 0],
        ['fqdn' => null, 'public_ipv4' => null, 'public_ipv6' => '2001:db8::1']
    );
    Once::flush();

    $user = User::factory()->create();

    $this->withHeaders([
        'X-Forwarded-Host' => 'evil.com',
    ])->get('/');

    $notification = new ResetPassword('poisoned-token', isTransactionalEmail: false);
    $url = callResetUrl($notification, $user);

    expect($url)
        ->toContain('[2001:db8::1]')
        ->toContain('poisoned-token')
        ->not->toContain('evil.com');
});

it('uses APP_URL fallback when no FQDN or public IPs are configured', function () {
    InstanceSettings::updateOrCreate(
        ['id' => 0],
        ['fqdn' => null, 'public_ipv4' => null, 'public_ipv6' => null]
    );
    Once::flush();

    config(['app.url' => 'http://my-coolify.local']);

    $user = User::factory()->create();

    $this->withHeaders([
        'X-Forwarded-Host' => 'evil.com',
    ])->get('/');

    $notification = new ResetPassword('fallback-token', isTransactionalEmail: false);
    $url = callResetUrl($notification, $user);

    expect($url)
        ->toStartWith('http://my-coolify.local/')
        ->toContain('fallback-token')
        ->not->toContain('evil.com');
});

it('generates a valid route path in the reset URL', function () {
    InstanceSettings::updateOrCreate(
        ['id' => 0],
        ['fqdn' => 'https://coolify.example.com']
    );
    Once::flush();

    $user = User::factory()->create();
    $notification = new ResetPassword('my-token', isTransactionalEmail: false);

    $url = callResetUrl($notification, $user);

    // Should contain the password reset route path with token and email
    expect($url)
        ->toContain('/reset-password/')
        ->toContain('my-token')
        ->toContain(urlencode($user->email));
});
