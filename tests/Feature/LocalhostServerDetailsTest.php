<?php

use App\Livewire\Server\Show;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->privateKey = PrivateKey::factory()->create([
        'team_id' => $this->team->id,
    ]);

    $this->server = Server::factory()->create([
        'id' => 0,
        'uuid' => 'localhost',
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
        'name' => 'localhost',
        'ip' => 'host.docker.internal',
        'user' => 'root',
        'port' => 22,
    ]);
});

function localhostMetadataProcessOutput(): string
{
    return implode("\n", [
        '---PRETTY_NAME---',
        'Ubuntu 22.04.3 LTS',
        '---ARCH---',
        'x86_64',
        '---KERNEL---',
        '5.15.0-91-generic',
        '---CPUS---',
        '4',
        '---MEMORY---',
        '8589934592',
        '---UPTIME_SINCE---',
        '2024-01-15 10:30:00',
        '---DOCKER---',
        '29.4.3-ce',
        '---COMPOSE---',
        'v2.32.4',
    ]);
}

it('shows a fetch server details action on localhost when metadata is missing', function () {
    $this->server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
    ]);

    Livewire::test(Show::class, ['server_uuid' => $this->server->uuid])
        ->assertSee('Fetch server details')
        ->assertDontSee('Ubuntu 22.04.3 LTS');
});

it('renders stored localhost server details instead of the fetch action', function () {
    $this->server->update([
        'server_metadata' => [
            'os' => 'Ubuntu 22.04.3 LTS',
            'arch' => 'x86_64',
            'kernel' => '5.15.0-91-generic',
            'cpus' => 4,
            'memory_bytes' => 8589934592,
            'uptime_since' => '2024-01-15 10:30:00',
            'collected_at' => now()->toIso8601String(),
        ],
    ]);

    $this->server->rememberDockerVersion('29.4.3-ce');
    $this->server->rememberComposeVersion('v2.32.4');

    Livewire::test(Show::class, ['server_uuid' => $this->server->uuid])
        ->assertSee('Operating system')
        ->assertSee('Ubuntu 22.04.3 LTS')
        ->assertSee('x86_64')
        ->assertSee('Docker version')
        ->assertSee('29.4.3')
        ->assertSee('Compose version')
        ->assertSee('2.32.4')
        ->assertSee('Refresh server details')
        ->assertDontSee('Fetch server details');
});

it('fetches localhost server details from the overview action', function () {
    $this->server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
    ]);

    Process::fake([
        '*' => Process::result(output: localhostMetadataProcessOutput(), exitCode: 0),
    ]);

    Livewire::test(Show::class, ['server_uuid' => $this->server->uuid])
        ->call('refreshServerMetadata')
        ->assertSee('Operating system')
        ->assertSee('Ubuntu 22.04.3 LTS')
        ->assertSee('Docker version')
        ->assertSee('29.4.3')
        ->assertSee('Compose version')
        ->assertSee('2.32.4');

    $server = $this->server->fresh();

    expect($server->server_metadata['os'])->toBe('Ubuntu 22.04.3 LTS')
        ->and($server->dockerVersion())->toBe('29.4.3')
        ->and($server->composeVersion())->toBe('2.32.4');
});

it('refetches localhost compose version from the overview refresh action', function () {
    $this->server->update([
        'server_metadata' => [
            'os' => 'Ubuntu 22.04.3 LTS',
            'arch' => 'x86_64',
            'kernel' => '5.15.0-91-generic',
            'cpus' => 4,
            'memory_bytes' => 8589934592,
            'uptime_since' => '2024-01-15 10:30:00',
            'collected_at' => now()->toIso8601String(),
        ],
    ]);
    $this->server->rememberDockerVersion('29.4.3');
    $this->server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
    ]);

    Process::fake([
        '*' => Process::result(output: localhostMetadataProcessOutput(), exitCode: 0),
    ]);

    Livewire::test(Show::class, ['server_uuid' => $this->server->uuid])
        ->assertSee('Compose version')
        ->assertSee('N/A')
        ->call('refreshServerMetadata')
        ->assertSee('2.32.4');

    expect($this->server->fresh()->composeVersion())->toBe('2.32.4');
});

it('collects localhost server details after a successful connection check', function () {
    $this->server->settings()->update([
        'is_reachable' => false,
        'is_usable' => false,
        'server_timezone' => 'UTC',
        'connection_timeout' => 10,
    ]);

    Process::fake([
        '*' => Process::sequence([
            Process::result(output: 'bin', exitCode: 0),
            Process::result(output: localhostMetadataProcessOutput(), exitCode: 0),
        ]),
    ]);

    Livewire::test(Show::class, ['server_uuid' => $this->server->uuid])
        ->call('checkLocalhostConnection');

    $server = $this->server->fresh();

    expect($server->server_metadata['os'])->toBe('Ubuntu 22.04.3 LTS')
        ->and($server->dockerVersion())->toBe('29.4.3')
        ->and($server->composeVersion())->toBe('2.32.4')
        ->and($server->settings->is_reachable)->toBeTrue()
        ->and($server->settings->is_usable)->toBeTrue();
});
