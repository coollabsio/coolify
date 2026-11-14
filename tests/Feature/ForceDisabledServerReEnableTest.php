<?php

use App\Models\InstanceSettings;
use App\Models\NotificationThrottle;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Notifications\Server\Unreachable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    config(['constants.coolify.self_hosted' => true, 'constants.ssh.mux_enabled' => false]);
    Storage::fake('ssh-keys');

    $team = Team::factory()->create();
    $this->server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
    ]);
    $this->server->forceDisableServer();
});

it('enables a disabled self-hosted server again when the validation connects', function () {
    Process::fake();

    $result = $this->server->validateConnection();

    expect($result)->toBe(['uptime' => true, 'error' => null])
        ->and($this->server->settings->fresh()->force_disabled)->toBeFalse()
        ->and($this->server->settings->fresh()->is_reachable)->toBeTrue();
});

it('keeps a self-hosted server disabled when the validation cannot connect', function () {
    Process::fake(['*' => Process::result(errorOutput: 'ssh: connect to host 192.0.2.1 port 22: Connection refused', exitCode: 255)]);

    $result = $this->server->validateConnection();

    expect($result['uptime'])->toBeFalse()
        ->and($result['error'])->not->toBe('Server skipped.')
        ->and($this->server->settings->fresh()->force_disabled)->toBeTrue();
});

it('does not enable a disabled server on cloud, where the flag enforces the plan limit', function () {
    config(['constants.coolify.self_hosted' => false]);
    Process::fake();

    expect($this->server->validateConnection())->toBe(['uptime' => false, 'error' => 'Server skipped.'])
        ->and($this->server->settings->fresh()->force_disabled)->toBeTrue();
    Process::assertNothingRan();
});

it('does not enable the archive of a transferred server', function () {
    $this->server->update(['server_metadata' => ['transfer' => ['status' => 'transferred']]]);
    Process::fake();

    expect($this->server->validateConnection())->toBe(['uptime' => false, 'error' => 'Server skipped.'])
        ->and($this->server->settings->fresh()->force_disabled)->toBeTrue();
    Process::assertNothingRan();
});

it('never disables the localhost server in the unreachable cleanup', function () {
    $localhost = Server::factory()->create(['id' => 0, 'team_id' => $this->server->team_id, 'unreachable_count' => 50]);
    $localhost->forceFill(['updated_at' => now()->subDays(8)])->saveQuietly();
    NotificationThrottle::record($localhost, Unreachable::class);

    $this->artisan('cleanup:unreachable-servers')->assertSuccessful();

    expect($localhost->settings->fresh()->force_disabled)->toBeFalse();
});
