<?php

use App\Models\AuditEvent;
use App\Models\InstanceSettings;
use Illuminate\Auth\Events\Failed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutDefer();
    InstanceSettings::forceCreate(['id' => 0]);
    Once::flush();
    Log::spy();
});

function pushToSentinelFromIp(string $ip, ?string $token = 'review-fix-e-invalid-token'): void
{
    $headers = $token === null ? [] : ['Authorization' => 'Bearer '.$token];

    test()->withServerVariables(['REMOTE_ADDR' => $ip])
        ->postJson('/api/v1/sentinel/push', ['containers' => []], $headers)
        ->assertUnauthorized();
}

function sentinelFailureReasons(): array
{
    return AuditEvent::query()
        ->where('event', 'webhook.sentinel.signature_failed')
        ->orderBy('id')
        ->get()
        ->map(fn (AuditEvent $event): string => $event->metadata['reason'].'@'.$event->ip_address)
        ->all();
}

test('a burst of failed sentinel pushes records one audit event per reason and source ip', function () {
    foreach (range(1, 5) as $attempt) {
        pushToSentinelFromIp('203.0.113.10');
    }
    pushToSentinelFromIp('203.0.113.10', token: null);
    pushToSentinelFromIp('203.0.113.11');

    expect(sentinelFailureReasons())->toBe([
        'decrypt_failed@203.0.113.10',
        'token_missing@203.0.113.10',
        'decrypt_failed@203.0.113.11',
    ]);
});

test('a failure is recorded again after the deduplication window', function () {
    pushToSentinelFromIp('203.0.113.10');
    $this->travel(4)->minutes();
    pushToSentinelFromIp('203.0.113.10');
    $this->travel(2)->minutes();
    pushToSentinelFromIp('203.0.113.10');

    expect(sentinelFailureReasons())->toBe([
        'decrypt_failed@203.0.113.10',
        'decrypt_failed@203.0.113.10',
    ]);
});

test('repeated unauthenticated api requests record one audit event per source ip', function () {
    foreach (range(1, 3) as $attempt) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.20'])->getJson('/api/v1/projects')->assertUnauthorized();
    }
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.21'])->getJson('/api/v1/projects')->assertUnauthorized();

    expect(AuditEvent::query()->where('event', 'api.auth.unauthenticated')->pluck('ip_address')->all())
        ->toBe(['203.0.113.20', '203.0.113.21']);
});

test('failed logins record one audit event per email and source ip in each window', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.30']);

    foreach (['victim@example.com', 'VICTIM@example.com', 'victim@example.com', 'other@example.com'] as $email) {
        event(new Failed('web', null, ['email' => $email, 'password' => 'wrong']));
    }
    $this->travel(61)->seconds();
    event(new Failed('web', null, ['email' => 'victim@example.com', 'password' => 'wrong']));

    expect(AuditEvent::query()->where('event', 'auth.user.login_failed')->orderBy('id')->pluck('metadata')->pluck('attempted_email')->all())
        ->toBe(['victim@example.com', 'other@example.com', 'victim@example.com']);
});

test('events outside the unauthenticated failure set are never deduplicated', function () {
    auditLog('webhook.github.deployment_queued', ['reason' => 'push']);
    auditLog('webhook.github.deployment_queued', ['reason' => 'push']);
    auditLog('api.auth.ability_denied', ['reason' => 'missing ability']);
    auditLog('api.auth.ability_denied', ['reason' => 'missing ability']);

    expect(AuditEvent::query()->count())->toBe(4);
});
