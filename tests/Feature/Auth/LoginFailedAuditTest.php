<?php

use App\Models\AuditEvent;
use App\Models\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutDefer();
    config()->set('app.maintenance.store', 'array');

    InstanceSettings::forceCreate(['id' => 0]);
    RateLimiter::clear('login');
    Once::flush();
    Log::spy();
});

test('a failed login with a non-email identifier does not store the typed text', function () {
    $this->post('/login', [
        'email' => 'MySecretPassw0rd!',
        'password' => 'wrong-password',
    ])->assertRedirect();

    $failed = AuditEvent::query()->where('event', 'auth.user.login_failed')->sole();

    expect(json_encode($failed->metadata))->not->toContain('MySecretPassw0rd!')
        ->and($failed->metadata['attempted_email'])->toBe('[invalid]');
});

test('a failed login with a valid email address records the attempted email', function () {
    $this->post('/login', [
        'email' => 'person@example.com',
        'password' => 'wrong-password',
    ])->assertRedirect();

    expect(AuditEvent::query()->where('event', 'auth.user.login_failed')->sole()->metadata['attempted_email'])
        ->toBe('person@example.com');
});
