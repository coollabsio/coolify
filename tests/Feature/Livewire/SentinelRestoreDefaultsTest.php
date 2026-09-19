<?php

use App\Actions\Server\StartSentinel;
use App\Livewire\Server\Sentinel;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Once;
use Livewire\Livewire;
use Lorisleiva\Actions\Decorators\JobDecorator;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::table('instance_settings')->insert(['id' => 0]);
    Once::flush();
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);
    session(['currentTeam' => $team]);
    $this->actingAs($user);

    $this->server = Server::factory()->create([
        'id' => 0,
        'team_id' => $team->id,
        'ip' => 'host.docker.internal',
    ]);
});

it('restores default Sentinel configuration without rotating credentials or disabling metrics', function () {
    $settings = $this->server->settings;
    $token = $settings->sentinel_token;
    $settings->forceFill([
        'is_metrics_enabled' => true,
        'is_sentinel_enabled' => true,
        'sentinel_custom_url' => 'https://coolify.example.com',
        'sentinel_metrics_refresh_rate_seconds' => 30,
        'sentinel_metrics_history_days' => 14,
        'sentinel_push_interval_seconds' => 120,
        'is_sentinel_debug_enabled' => true,
    ])->saveQuietly();
    Queue::fake();

    Livewire::test(Sentinel::class, ['server' => $this->server])
        ->set('sentinelCustomDockerImage', 'sentinel:development')
        ->call('restoreDefaultConfiguration')
        ->assertSet('sentinelCustomUrl', 'http://coolify:8080')
        ->assertSet('isSentinelDebugEnabled', false)
        ->assertSet('sentinelCustomDockerImage', null)
        ->assertDispatched('sentinel-defaults-restored')
        ->assertDispatched('success', 'Default Sentinel configuration restored. Restarting Sentinel.');

    Queue::assertPushed(JobDecorator::class, fn (JobDecorator $job): bool => $job->getAction() instanceof StartSentinel);
    expect(Queue::pushed(JobDecorator::class))->toHaveCount(1);

    $settings->refresh();

    expect($settings->sentinel_token)->toBe($token)
        ->and((bool) $settings->is_metrics_enabled)->toBeTrue()
        ->and((bool) $settings->is_sentinel_enabled)->toBeTrue()
        ->and($settings->sentinel_custom_url)->toBe('http://coolify:8080')
        ->and($settings->sentinel_metrics_refresh_rate_seconds)->toBe(10)
        ->and($settings->sentinel_metrics_history_days)->toBe(7)
        ->and($settings->sentinel_push_interval_seconds)->toBe(60)
        ->and((bool) $settings->is_sentinel_debug_enabled)->toBeFalse();
});

it('offers a confirmed restore action and explains what it preserves', function () {
    Livewire::test(Sentinel::class, ['server' => $this->server])
        ->assertSee('Restore defaults')
        ->assertSee('The Sentinel token and metrics setting will be preserved.');
});

it('does not let team members restore Sentinel defaults', function () {
    $member = User::factory()->create();
    $this->server->team->members()->attach($member->id, ['role' => 'member']);
    $before = $this->server->settings->fresh()->getAttributes();
    $this->actingAs($member);
    session(['currentTeam' => $this->server->team]);

    Livewire::test(Sentinel::class, ['server' => $this->server])
        ->call('restoreDefaultConfiguration');

    expect($this->server->settings->fresh()->getAttributes())->toBe($before);
});

it('shows local troubleshooting guidance when Sentinel is out of sync', function () {
    $this->server->sentinelHeartbeat(isReset: true);

    Livewire::test(Sentinel::class, ['server' => $this->server])
        ->assertSee('Sentinel has not reported within the expected interval.')
        ->assertSee('Open Sentinel logs')
        ->assertSeeHtml('href="'.route('server.sentinel.logs', ['server_uuid' => $this->server->uuid]).'"')
        ->assertDontSee('docker logs --tail 100 coolify-sentinel')
        ->assertSee('Sync Sentinel to recreate it on the Coolify Docker network.')
        ->assertDontSee('The remote server needs outbound access');
});

it('shows remote connectivity checks when Sentinel is out of sync', function () {
    $remoteServer = Server::factory()->create([
        'team_id' => $this->server->team_id,
        'ip' => '192.0.2.10',
    ]);
    $remoteServer->settings->forceFill([
        'sentinel_custom_url' => 'https://coolify.example.com',
    ])->saveQuietly();
    $remoteServer->sentinelHeartbeat(isReset: true);

    Livewire::test(Sentinel::class, ['server' => $remoteServer])
        ->assertSee('The remote server needs outbound access to this Coolify URL.')
        ->assertSee('https://coolify.example.com/api/health')
        ->assertSee('Check DNS, TLS certificates, outbound firewall rules, and proxy settings.')
        ->assertDontSee('Sync Sentinel to recreate it on the Coolify Docker network.');
});

it('shell quotes the remote health URL in troubleshooting guidance', function () {
    $remoteServer = Server::factory()->create([
        'team_id' => $this->server->team_id,
        'ip' => '192.0.2.10',
    ]);
    $remoteServer->settings->forceFill([
        'sentinel_custom_url' => 'https://coolify.example.com/$(id)',
    ])->saveQuietly();
    $remoteServer->sentinelHeartbeat(isReset: true);

    Livewire::test(Sentinel::class, ['server' => $remoteServer])
        ->assertSee("curl -fsS 'https://coolify.example.com/\$(id)/api/health'");
});

it('does not show troubleshooting guidance while Sentinel is live', function () {
    $this->server->sentinelHeartbeat();

    Livewire::test(Sentinel::class, ['server' => $this->server])
        ->assertDontSee('Sentinel has not reported within the expected interval.')
        ->assertDontSee('Open Sentinel logs');
});

it('shows restarting until the sentinel container starts', function () {
    Queue::fake();

    Livewire::test(Sentinel::class, ['server' => $this->server])
        ->call('restartSentinel')
        ->assertSet('sentinelStatus', 'restarting')
        ->assertSee('Restarting')
        ->assertDispatched('sentinel-status-changed', outOfSync: false, expiresInMilliseconds: 90000);
});

it('shows waiting for first report after the sentinel container starts', function () {
    $this->server->forceFill(['sentinel_waiting_since' => now()])->save();

    Livewire::test(Sentinel::class, ['server' => $this->server])
        ->call('handleSentinelRestarted', ['serverUuid' => $this->server->uuid])
        ->assertSet('sentinelStatus', 'waiting')
        ->assertSee('Waiting for first report')
        ->assertDontSee('Sentinel is out of sync');
});

it('uses one push interval plus thirty seconds for the first report timeout', function () {
    $this->server->settings->forceFill(['sentinel_push_interval_seconds' => 60])->saveQuietly();

    expect($this->server->firstSentinelReportTimeoutSeconds())->toBe(90)
        ->and($this->server->waitBeforeDoingSshCheck())->toBe(180);

    $this->server->forceFill(['sentinel_waiting_since' => now()->subSeconds(89)])->save();
    expect($this->server->fresh()->sentinelStatus())->toBe('waiting');

    $this->server->forceFill(['sentinel_waiting_since' => now()->subSeconds(91)])->save();
    expect($this->server->fresh()->sentinelStatus())->toBe('out_of_sync');
});

it('leaves restarting state when no container-start event arrives before the timeout', function () {
    $this->server->sentinelHeartbeat(isReset: true);

    Livewire::test(Sentinel::class, ['server' => $this->server])
        ->set('sentinelStatus', 'restarting')
        ->set('sentinelRestartRequestedAt', now()->subSeconds($this->server->firstSentinelReportTimeoutSeconds() + 1)->timestamp)
        ->call('refreshSentinelStatus')
        ->assertSet('sentinelStatus', 'out_of_sync');
});

it('shows in sync after the first authenticated report', function () {
    $this->server->sentinelHeartbeat();

    Livewire::test(Sentinel::class, ['server' => $this->server])
        ->call('handleSentinelSynchronized', ['serverUuid' => $this->server->uuid])
        ->assertSet('sentinelStatus', 'in_sync')
        ->assertSee('In sync');
});

it('shows out of sync when the first report timeout expires', function () {
    $this->server->forceFill([
        'sentinel_waiting_since' => now()->subSeconds($this->server->firstSentinelReportTimeoutSeconds() + 1),
    ])->save();

    Livewire::test(Sentinel::class, ['server' => $this->server->fresh()])
        ->assertSet('sentinelStatus', 'out_of_sync')
        ->assertSee('Out of sync');
});
