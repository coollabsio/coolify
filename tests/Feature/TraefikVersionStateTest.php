<?php

use App\Enums\ProxyTypes;
use App\Events\ProxyStatusChangedUI;
use App\Jobs\CheckTraefikVersionForServerJob;
use App\Jobs\CheckTraefikVersionJob;
use App\Livewire\Server\Proxy;
use App\Models\Server;
use App\Models\Team;
use App\Notifications\Server\TraefikVersionOutdated;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

it('ignores stale minor upgrade information for the detected Traefik version', function () {
    Cache::put('coolify:versions:all', [
        'traefik' => [
            'v3.7' => '3.7.8',
            'v3.6' => '3.6.23',
        ],
    ]);

    $server = Server::factory()->make([
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value],
        'detected_traefik_version' => '3.7.8',
        'traefik_outdated_info' => [
            'current' => '3.6.23',
            'latest' => '3.7.8',
            'type' => 'minor_upgrade',
            'upgrade_target' => 'v3.7',
        ],
    ]);

    $component = new Proxy;
    $component->server = $server;

    expect($component->getNewerTraefikBranchAvailableProperty())->toBeNull();
});

it('does not offer the Traefik branch already configured on a running proxy', function () {
    Cache::put('coolify:versions:all', [
        'traefik' => [
            'v3.7' => '3.7.8',
            'v3.6' => '3.6.23',
        ],
    ]);

    $server = Server::factory()->make([
        'proxy' => [
            'type' => ProxyTypes::TRAEFIK->value,
            'status' => 'running',
        ],
        'detected_traefik_version' => '3.6.23',
        'traefik_outdated_info' => [
            'current' => '3.6.23',
            'latest' => '3.7.8',
            'type' => 'minor_upgrade',
            'upgrade_target' => 'v3.7',
        ],
    ]);

    $component = new Proxy;
    $component->server = $server;
    $component->proxySettings = <<<'YAML'
services:
  traefik:
    image: 'traefik:v3.7'
YAML;

    expect($component->getNewerTraefikBranchAvailableProperty())->toBeNull();
});

it('still offers a newer Traefik branch than the configured image', function () {
    Cache::put('coolify:versions:all', [
        'traefik' => [
            'v3.7' => '3.7.8',
            'v3.6' => '3.6.23',
        ],
    ]);

    $server = Server::factory()->make([
        'proxy' => [
            'type' => ProxyTypes::TRAEFIK->value,
            'status' => 'running',
        ],
        'detected_traefik_version' => '3.6.23',
    ]);

    $component = new Proxy;
    $component->server = $server;
    $component->proxySettings = 'services:'.PHP_EOL.'  traefik:'.PHP_EOL.'    image: traefik:v3.6';

    expect($component->getNewerTraefikBranchAvailableProperty())->toBe('v3.7');
});

it('clears the stale minor warning after the configured branch is applied', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'proxy' => [
            'type' => ProxyTypes::TRAEFIK->value,
            'status' => 'running',
            'last_saved_proxy_configuration' => <<<'YAML'
services:
  traefik:
    image: traefik:v3.7
YAML,
        ],
        'detected_traefik_version' => '3.6.23',
        'traefik_outdated_info' => [
            'current' => '3.6.23',
            'latest' => '3.7.8',
            'type' => 'minor_upgrade',
            'upgrade_target' => 'v3.7',
        ],
    ]);

    $component = new Proxy;
    $component->server = $server;
    $component->mount();

    expect($server->refresh()->traefik_outdated_info)->toBeNull();
});

it('preserves a newer Traefik warning stored after the warning was inspected', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'proxy' => [
            'type' => ProxyTypes::TRAEFIK->value,
            'status' => 'running',
        ],
        'detected_traefik_version' => '3.6.23',
        'traefik_outdated_info' => [
            'current' => '3.6.23',
            'latest' => '3.7.8',
            'type' => 'minor_upgrade',
            'upgrade_target' => 'v3.7',
        ],
    ]);

    $component = new Proxy;
    $component->server = $server;
    $component->proxySettings = 'services:'.PHP_EOL.'  traefik:'.PHP_EOL.'    image: traefik:v3.7';

    $newerWarning = [
        'current' => '3.7.8',
        'latest' => '3.8.1',
        'type' => 'minor_upgrade',
        'upgrade_target' => 'v3.8',
    ];
    Server::query()->whereKey($server->id)->update(['traefik_outdated_info' => $newerWarning]);

    $method = new ReflectionMethod($component, 'clearAppliedTraefikBranchWarning');
    $method->invoke($component);

    expect($server->refresh()->traefik_outdated_info)->toBe($newerWarning);
});

it('does not mark stale Traefik outdated information as a current warning', function () {
    $server = Server::factory()->make([
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value],
        'detected_traefik_version' => '3.7.8',
        'traefik_outdated_info' => [
            'current' => '3.6.23',
            'latest' => '3.7.8',
            'type' => 'minor_upgrade',
            'upgrade_target' => 'v3.7',
        ],
    ]);

    expect($server->hasCurrentTraefikOutdatedInfo())->toBeFalse();
});

it('marks matching Traefik outdated information as a current warning', function () {
    $server = Server::factory()->make([
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value],
        'detected_traefik_version' => 'v3.6.23',
        'traefik_outdated_info' => [
            'current' => '3.6.23',
            'latest' => '3.7.8',
            'type' => 'minor_upgrade',
            'upgrade_target' => 'v3.7',
        ],
    ]);

    expect($server->hasCurrentTraefikOutdatedInfo())->toBeTrue();
});

it('does not clear an active Traefik warning before a check can establish its outcome', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'proxy' => [
            'type' => ProxyTypes::TRAEFIK->value,
            'status' => 'running',
        ],
        'detected_traefik_version' => '3.6.23',
        'traefik_outdated_info' => [
            'current' => '3.6.23',
            'latest' => '3.7.8',
            'type' => 'minor_upgrade',
            'upgrade_target' => 'v3.7',
        ],
    ]);

    $job = new class($server, ['v3.7' => '3.7.8']) extends CheckTraefikVersionForServerJob
    {
        protected function detectCurrentVersion(): ?string
        {
            return null;
        }
    };

    $job->handle();

    expect($server->refresh()->traefik_outdated_info)->not->toBeNull()
        ->and($server->traefik_outdated_info['current'])->toBe('3.6.23');
});

it('clears Traefik version state when the proxy changes', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'proxy' => [
            'type' => ProxyTypes::TRAEFIK->value,
            'status' => 'running',
        ],
        'detected_traefik_version' => '3.6.23',
        'traefik_outdated_info' => [
            'current' => '3.6.23',
            'latest' => '3.7.8',
            'type' => 'minor_upgrade',
        ],
    ]);

    $server->changeProxy(ProxyTypes::NONE->value);

    expect($server->refresh()->detected_traefik_version)->toBeNull()
        ->and($server->traefik_outdated_info)->toBeNull();
});

it('cleans stale Traefik version state while selecting servers to check', function () {
    Bus::fake();
    Cache::put('coolify:versions:all', [
        'traefik' => ['v3.7' => '3.7.8'],
    ]);

    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'proxy' => [
            'type' => ProxyTypes::NONE->value,
            'status' => 'exited',
        ],
        'detected_traefik_version' => '3.6.23',
        'traefik_outdated_info' => [
            'current' => '3.6.23',
            'latest' => '3.7.8',
            'type' => 'minor_upgrade',
        ],
    ]);

    (new CheckTraefikVersionJob)->handle();

    expect($server->refresh()->detected_traefik_version)->toBeNull()
        ->and($server->traefik_outdated_info)->toBeNull();
});

it('does not inspect a server after its Traefik proxy has been disabled', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'proxy' => [
            'type' => ProxyTypes::NONE->value,
            'status' => 'exited',
        ],
        'detected_traefik_version' => '3.6.23',
        'traefik_outdated_info' => [
            'current' => '3.6.23',
            'latest' => '3.7.8',
            'type' => 'minor_upgrade',
        ],
    ]);
    Event::fake();

    (new CheckTraefikVersionForServerJob($server, ['v3.7' => '3.7.8']))->handle();

    expect($server->refresh()->detected_traefik_version)->toBeNull()
        ->and($server->traefik_outdated_info)->toBeNull();

    Event::assertNotDispatched(ProxyStatusChangedUI::class);
});

function createServerForTraefikAlertDeduplication(): array
{
    $team = Team::factory()->create();
    $team->emailNotificationSettings->update([
        'smtp_enabled' => true,
        'traefik_outdated_email_notifications' => true,
    ]);
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'proxy' => [
            'type' => ProxyTypes::TRAEFIK->value,
            'status' => 'running',
        ],
    ]);

    Notification::fake();

    return [$team, $server];
}

function recordTraefikOutdatedDetection(
    CheckTraefikVersionForServerJob $job,
    string $current = '3.6.25',
    string $latest = '3.7.13',
    string $type = 'minor_upgrade',
    ?string $upgradeTarget = 'v3.7',
    ?array $newerBranchInfo = null,
): void {
    $method = new ReflectionMethod($job, 'storeOutdatedInfo');
    $method->invoke($job, $current, $latest, $type, $upgradeTarget, $newerBranchInfo);
}

afterEach(function () {
    Carbon::setTestNow();
});

it('sends the first Traefik warning and preserves UI-compatible state', function () {
    [$team, $server] = createServerForTraefikAlertDeduplication();
    Carbon::setTestNow('2026-09-16 08:00:00');

    recordTraefikOutdatedDetection(new CheckTraefikVersionForServerJob($server, []));

    Notification::assertSentToTimes($team, TraefikVersionOutdated::class, 1);

    $state = $server->refresh()->traefik_outdated_info;

    expect($state['current'])->toBe('3.6.25')
        ->and($state['latest'])->toBe('3.7.13')
        ->and($state['type'])->toBe('minor_upgrade')
        ->and($state['upgrade_target'])->toBe('v3.7')
        ->and($state['checked_at'])->toBe(now()->toIso8601String())
        ->and($state['first_seen_at'])->toBe(now()->toIso8601String())
        ->and($state['last_seen_at'])->toBe(now()->toIso8601String())
        ->and($state['last_notified_at'])->toBe(now()->toIso8601String())
        ->and($state['fingerprint'])->toMatch('/^[a-f0-9]{64}$/');
});

it('adopts matching legacy state without a duplicate notification', function () {
    [$team, $server] = createServerForTraefikAlertDeduplication();
    $server->update([
        'traefik_outdated_info' => [
            'current' => '3.6.25',
            'latest' => '3.7.13',
            'type' => 'minor_upgrade',
            'upgrade_target' => 'v3.7',
            'checked_at' => '2026-09-16T07:00:00+00:00',
        ],
    ]);
    Carbon::setTestNow('2026-09-16 08:00:00');

    recordTraefikOutdatedDetection(new CheckTraefikVersionForServerJob($server->fresh(), []));

    Notification::assertNothingSent();
    expect($server->refresh()->traefik_outdated_info['fingerprint'])->toMatch('/^[a-f0-9]{64}$/')
        ->and($server->traefik_outdated_info['last_notified_at'])->toBe(now()->toIso8601String());

    Carbon::setTestNow('2026-09-17 08:00:00');
    recordTraefikOutdatedDetection(new CheckTraefikVersionForServerJob($server->fresh(), []));

    Notification::assertSentToTimes($team, TraefikVersionOutdated::class, 1);
});

it('suppresses identical warnings until exactly 24 hours and resets the reminder window', function () {
    [$team, $server] = createServerForTraefikAlertDeduplication();
    Carbon::setTestNow('2026-09-16 08:00:00');
    $job = new CheckTraefikVersionForServerJob($server, []);

    recordTraefikOutdatedDetection($job);

    Carbon::setTestNow('2026-09-16 09:00:00');
    recordTraefikOutdatedDetection($job);
    Notification::assertSentToTimes($team, TraefikVersionOutdated::class, 1);

    Carbon::setTestNow('2026-09-17 07:59:59');
    recordTraefikOutdatedDetection($job);
    Notification::assertSentToTimes($team, TraefikVersionOutdated::class, 1);

    Carbon::setTestNow('2026-09-17 08:00:00');
    recordTraefikOutdatedDetection($job);
    Notification::assertSentToTimes($team, TraefikVersionOutdated::class, 2);
    $remindedAt = now()->toIso8601String();

    Carbon::setTestNow('2026-09-17 08:00:01');
    recordTraefikOutdatedDetection($job);
    Notification::assertSentToTimes($team, TraefikVersionOutdated::class, 2);

    expect($server->refresh()->traefik_outdated_info['last_notified_at'])
        ->toBe($remindedAt);
});

it('notifies immediately when the latest or installed Traefik version changes', function () {
    [$team, $server] = createServerForTraefikAlertDeduplication();
    Carbon::setTestNow('2026-09-16 08:00:00');
    $job = new CheckTraefikVersionForServerJob($server, []);

    recordTraefikOutdatedDetection($job, '3.6.25', '3.7.13');

    Carbon::setTestNow('2026-09-16 09:00:00');
    recordTraefikOutdatedDetection($job, '3.6.25', '3.7.14');

    Carbon::setTestNow('2026-09-16 10:00:00');
    recordTraefikOutdatedDetection($job, '3.6.26', '3.7.14');

    Notification::assertSentToTimes($team, TraefikVersionOutdated::class, 3);
});

it('canonicalizes optional branch data independently of key order', function () {
    [, $server] = createServerForTraefikAlertDeduplication();
    Carbon::setTestNow('2026-09-16 08:00:00');
    $job = new CheckTraefikVersionForServerJob($server, []);

    recordTraefikOutdatedDetection($job, '3.6.25', '3.6.26', 'patch_update', null, [
        'target' => 'v3.7',
        'latest' => '3.7.13',
    ]);
    $firstFingerprint = $server->refresh()->traefik_outdated_info['fingerprint'];

    Carbon::setTestNow('2026-09-16 09:00:00');
    recordTraefikOutdatedDetection($job, '3.6.25', '3.6.26', 'patch_update', null, [
        'latest' => '3.7.13',
        'target' => 'v3.7',
    ]);

    expect($server->refresh()->traefik_outdated_info['fingerprint'])->toBe($firstFingerprint);
    Notification::assertNothingSent();
});

it('includes the server identity in the Traefik warning fingerprint', function () {
    [$team, $firstServer] = createServerForTraefikAlertDeduplication();
    $secondServer = Server::factory()->create([
        'team_id' => $team->id,
        'proxy' => [
            'type' => ProxyTypes::TRAEFIK->value,
            'status' => 'running',
        ],
    ]);
    Carbon::setTestNow('2026-09-16 08:00:00');

    recordTraefikOutdatedDetection(new CheckTraefikVersionForServerJob($firstServer, []));
    $secondServer->update(['traefik_outdated_info' => $firstServer->refresh()->traefik_outdated_info]);
    recordTraefikOutdatedDetection(new CheckTraefikVersionForServerJob($secondServer, []));

    Notification::assertSentToTimes($team, TraefikVersionOutdated::class, 2);
});

it('clears a resolved warning and notifies when the same warning returns', function () {
    [$team, $server] = createServerForTraefikAlertDeduplication();
    Carbon::setTestNow('2026-09-16 08:00:00');
    $job = new CheckTraefikVersionForServerJob($server, []);

    recordTraefikOutdatedDetection($job);

    $method = new ReflectionMethod($job, 'resolveOutdatedInfo');
    $method->invoke($job);
    expect($server->refresh()->traefik_outdated_info)->toBeNull();

    Carbon::setTestNow('2026-09-16 09:00:00');
    recordTraefikOutdatedDetection(new CheckTraefikVersionForServerJob($server->fresh(), []));

    Notification::assertSentToTimes($team, TraefikVersionOutdated::class, 2);
});

it('keeps dedupe state when a transient version check fails', function () {
    [$team, $server] = createServerForTraefikAlertDeduplication();
    Carbon::setTestNow('2026-09-16 08:00:00');
    recordTraefikOutdatedDetection(new CheckTraefikVersionForServerJob($server, []));
    $persistedState = $server->refresh()->traefik_outdated_info;

    Carbon::setTestNow('2026-09-16 09:00:00');
    $failedCheck = new class($server->fresh(), ['v3.7' => '3.7.13']) extends CheckTraefikVersionForServerJob
    {
        protected function detectCurrentVersion(): ?string
        {
            return null;
        }
    };
    $failedCheck->handle();

    expect($server->refresh()->traefik_outdated_info)->toBe($persistedState);
    Notification::assertSentToTimes($team, TraefikVersionOutdated::class, 1);

    Carbon::setTestNow('2026-09-16 10:00:00');
    recordTraefikOutdatedDetection(new CheckTraefikVersionForServerJob($server->fresh(), []));
    Notification::assertSentToTimes($team, TraefikVersionOutdated::class, 1);
});

it('keeps dedupe state when image inspection or version parsing fails', function () {
    [$team, $server] = createServerForTraefikAlertDeduplication();
    Carbon::setTestNow('2026-09-16 08:00:00');
    recordTraefikOutdatedDetection(new CheckTraefikVersionForServerJob($server, []));
    $persistedState = $server->refresh()->traefik_outdated_info;

    $imageInspectionFailure = new class($server->fresh(), ['v3.7' => '3.7.13']) extends CheckTraefikVersionForServerJob
    {
        protected function detectCurrentVersion(): ?string
        {
            return '3.6.25';
        }

        protected function detectImageTag(): ?string
        {
            return null;
        }
    };
    $imageInspectionFailure->handle();

    expect($server->refresh()->traefik_outdated_info)->toBe($persistedState);

    $parsingFailure = new class($server->fresh(), ['v3.7' => '3.7.13']) extends CheckTraefikVersionForServerJob
    {
        protected function detectCurrentVersion(): ?string
        {
            return 'unparseable';
        }

        protected function detectImageTag(): ?string
        {
            return 'traefik:v3.6';
        }
    };
    $parsingFailure->handle();

    expect($server->refresh()->traefik_outdated_info)->toBe($persistedState);
    Notification::assertSentToTimes($team, TraefikVersionOutdated::class, 1);
});

it('resolves persisted warnings only after a reliable current or latest-tag result', function () {
    [, $server] = createServerForTraefikAlertDeduplication();
    Carbon::setTestNow('2026-09-16 08:00:00');
    recordTraefikOutdatedDetection(new CheckTraefikVersionForServerJob($server, []));

    $currentCheck = new class($server->fresh(), ['v3.7' => '3.7.13']) extends CheckTraefikVersionForServerJob
    {
        protected function detectCurrentVersion(): ?string
        {
            return '3.7.13';
        }

        protected function detectImageTag(): ?string
        {
            return 'traefik:v3.7';
        }
    };
    $currentCheck->handle();

    expect($server->refresh()->traefik_outdated_info)->toBeNull();

    recordTraefikOutdatedDetection(new CheckTraefikVersionForServerJob($server->fresh(), []));

    $latestTagCheck = new class($server->fresh(), ['v3.7' => '3.7.13']) extends CheckTraefikVersionForServerJob
    {
        protected function detectCurrentVersion(): ?string
        {
            return '3.6.25';
        }

        protected function detectImageTag(): ?string
        {
            return 'traefik:latest';
        }
    };
    $latestTagCheck->handle();

    expect($server->refresh()->traefik_outdated_info)->toBeNull();
});

it('suppresses an identical warning after a new job instance loads persisted state', function () {
    [$team, $server] = createServerForTraefikAlertDeduplication();
    Carbon::setTestNow('2026-09-16 08:00:00');

    recordTraefikOutdatedDetection(new CheckTraefikVersionForServerJob($server, []));

    Carbon::setTestNow('2026-09-16 09:00:00');
    recordTraefikOutdatedDetection(new CheckTraefikVersionForServerJob($server->fresh(), []));

    Notification::assertSentToTimes($team, TraefikVersionOutdated::class, 1);
});

it('uses a durable server-scoped uniqueness key and atomically reserves one notification', function () {
    [$team, $server] = createServerForTraefikAlertDeduplication();
    Carbon::setTestNow('2026-09-16 08:00:00');
    $firstJob = new CheckTraefikVersionForServerJob($server, []);
    $secondJob = new CheckTraefikVersionForServerJob($server->fresh(), []);

    expect(class_implements($firstJob))->toContain(ShouldBeUnique::class)
        ->and($firstJob->uniqueId())->toBe($secondJob->uniqueId());

    recordTraefikOutdatedDetection($firstJob);
    recordTraefikOutdatedDetection($secondJob);

    Notification::assertSentToTimes($team, TraefikVersionOutdated::class, 1);
});
