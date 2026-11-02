<?php

use App\Livewire\Server\Show;
use App\Livewire\Server\ValidateAndInstall;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);

    $user = User::factory()->create();
    $this->team = Team::factory()->create();
    $user->teams()->attach($this->team, ['role' => 'owner']);
    $this->actingAs($user);
    session(['currentTeam' => $this->team]);

    $this->privateKey = PrivateKey::factory()->create([
        'team_id' => $this->team->id,
    ]);

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
    ]);
});

it('casts server_metadata as array', function () {
    $metadata = [
        'os' => 'Ubuntu 22.04.3 LTS',
        'arch' => 'x86_64',
        'kernel' => '5.15.0-91-generic',
        'cpus' => 4,
        'memory_bytes' => 8589934592,
        'uptime_since' => '2024-01-15 10:30:00',
        'collected_at' => now()->toIso8601String(),
    ];

    $this->server->update(['server_metadata' => $metadata]);
    $this->server->refresh();

    expect($this->server->server_metadata)->toBeArray()
        ->and($this->server->server_metadata['os'])->toBe('Ubuntu 22.04.3 LTS')
        ->and($this->server->server_metadata['cpus'])->toBe(4)
        ->and($this->server->server_metadata['memory_bytes'])->toBe(8589934592);
});

it('stores null server_metadata by default', function () {
    expect($this->server->server_metadata)->toBeNull();
});

it('includes server_metadata in fillable', function () {
    $this->server->fill(['server_metadata' => ['os' => 'Test']]);

    expect($this->server->server_metadata)->toBe(['os' => 'Test']);
});

it('persists and retrieves full server metadata structure', function () {
    $metadata = [
        'os' => 'Debian GNU/Linux 12 (bookworm)',
        'arch' => 'aarch64',
        'kernel' => '6.1.0-17-arm64',
        'cpus' => 8,
        'memory_bytes' => 17179869184,
        'uptime_since' => '2024-03-01 08:00:00',
        'collected_at' => '2024-03-10T12:00:00+00:00',
    ];

    $this->server->update(['server_metadata' => $metadata]);
    $this->server->refresh();

    expect($this->server->server_metadata)
        ->toHaveKeys(['os', 'arch', 'kernel', 'cpus', 'memory_bytes', 'uptime_since', 'collected_at'])
        ->and($this->server->server_metadata['os'])->toBe('Debian GNU/Linux 12 (bookworm)')
        ->and($this->server->server_metadata['arch'])->toBe('aarch64')
        ->and($this->server->server_metadata['cpus'])->toBe(8)
        ->and(round($this->server->server_metadata['memory_bytes'] / 1073741824, 1))->toBe(16.0);
});

it('returns null from gatherServerMetadata when server is not functional', function () {
    $this->server->settings->update([
        'is_reachable' => false,
        'is_usable' => false,
    ]);

    $this->server->refresh();

    expect($this->server->gatherServerMetadata())->toBeNull();
});

it('stores a parsed docker version when gathering server metadata', function () {
    $this->server->settings->update([
        'is_reachable' => true,
        'is_usable' => true,
    ]);

    Process::fake([
        '*' => Process::result(output: implode("\n", [
            '---PRETTY_NAME---',
            'Debian GNU/Linux 12 (bookworm)',
            '---ARCH---',
            'aarch64',
            '---KERNEL---',
            '6.1.0-17-arm64',
            '---CPUS---',
            '8',
            '---MEMORY---',
            '17179869184',
            '---UPTIME_SINCE---',
            '2024-03-01 08:00:00',
            '---DOCKER---',
            '29.4.3-ce',
            '---COMPOSE---',
            'v2.32.4',
        ]), exitCode: 0),
    ]);

    expect($this->server->gatherServerMetadata()['os'])->toBe('Debian GNU/Linux 12 (bookworm)')
        ->and($this->server->fresh()->dockerVersion())->toBe('29.4.3')
        ->and($this->server->fresh()->composeVersion())->toBe('2.32.4');
});

it('keeps transfer and migration state when gathering server metadata', function () {
    $this->server->settings->update([
        'is_reachable' => true,
        'is_usable' => true,
    ]);
    // Loaded before the transfer state is written, like a validation that runs during a transfer.
    $staleServer = Server::find($this->server->id);

    $this->server->update(['server_metadata' => [
        'os' => 'Old OS',
        'transfer' => ['status' => 'claimed', 'export_id' => 'export-1'],
        'migration' => ['status' => 'succeeded', 'export_id' => 'export-1'],
    ]]);

    Process::fake([
        '*' => Process::result(output: "---PRETTY_NAME---\nDebian GNU/Linux 12 (bookworm)\n---CPUS---\n2", exitCode: 0),
    ]);

    $staleServer->gatherServerMetadata();

    expect($this->server->fresh()->server_metadata)
        ->toMatchArray([
            'os' => 'Debian GNU/Linux 12 (bookworm)',
            'cpus' => 2,
            'transfer' => ['status' => 'claimed', 'export_id' => 'export-1'],
            'migration' => ['status' => 'succeeded', 'export_id' => 'export-1'],
        ]);
});

it('shows the stored docker version in remote server details', function () {
    $this->server->update([
        'server_metadata' => [
            'os' => 'Debian GNU/Linux 12 (bookworm)',
            'arch' => 'aarch64',
            'kernel' => '6.1.0-17-arm64',
            'cpus' => 8,
            'memory_bytes' => 17179869184,
            'uptime_since' => '2024-03-01 08:00:00',
            'collected_at' => now()->toIso8601String(),
        ],
    ]);
    $this->server->rememberDockerVersion('27.5.1');
    $this->server->rememberComposeVersion('2.29.7');

    Livewire::test(Show::class, ['server_uuid' => $this->server->uuid])
        ->assertSee('Docker version')
        ->assertSee('27.5.1')
        ->assertSee('Compose version')
        ->assertSee('2.29.7');
});

it('can overwrite server_metadata with new values', function () {
    $this->server->update(['server_metadata' => ['os' => 'Ubuntu 20.04', 'cpus' => 2]]);
    $this->server->refresh();

    expect($this->server->server_metadata['os'])->toBe('Ubuntu 20.04');

    $this->server->update(['server_metadata' => ['os' => 'Ubuntu 22.04', 'cpus' => 4]]);
    $this->server->refresh();

    expect($this->server->server_metadata['os'])->toBe('Ubuntu 22.04')
        ->and($this->server->server_metadata['cpus'])->toBe(4);
});

it('calls gatherServerMetadata during ValidateAndInstall when docker version is valid', function () {
    Queue::fake();

    $serverMock = Mockery::mock($this->server)->makePartial();
    $serverMock->shouldReceive('isSwarm')->andReturn(false);
    $serverMock->shouldReceive('validateDockerEngineVersion')->once()->andReturn('24.0.0');
    $serverMock->shouldReceive('gatherServerMetadata')->once();
    $serverMock->shouldReceive('isBuildServer')->andReturn(false);

    // Call the action on the mounted instance: a Livewire request round trip
    // would rehydrate the server from the database and drop the mock.
    $component = Livewire::test(ValidateAndInstall::class, ['server' => $serverMock])->instance();
    $component->uptime = '1 day';
    $component->validateDockerVersion();
});

it('does not call gatherServerMetadata when docker version validation fails', function () {
    $serverMock = Mockery::mock($this->server)->makePartial();
    $serverMock->shouldReceive('isSwarm')->andReturn(false);
    $serverMock->shouldReceive('validateDockerEngineVersion')->once()->andReturn(false);
    $serverMock->shouldNotReceive('gatherServerMetadata');

    // Call the action on the mounted instance: a Livewire request round trip
    // would rehydrate the server from the database and drop the mock.
    $component = Livewire::test(ValidateAndInstall::class, ['server' => $serverMock])->instance();
    $component->uptime = '1 day';
    $component->validateDockerVersion();
});

it('stores detected NVIDIA GPUs and the container toolkit when gathering server metadata', function (array $gpuLines, array $toolkitLines, array $expectedGpus, bool $expectedToolkit) {
    $this->server->settings->update([
        'is_reachable' => true,
        'is_usable' => true,
    ]);

    Process::fake([
        '*' => Process::result(output: implode("\n", [
            '---PRETTY_NAME---',
            'Ubuntu 24.04 LTS',
            '---CPUS---',
            '8',
            '---DOCKER---',
            '29.4.3-ce',
            '---COMPOSE---',
            'v2.32.4',
            '---GPUS---',
            ...$gpuLines,
            '---NVIDIA_TOOLKIT---',
            ...$toolkitLines,
        ]), exitCode: 0),
    ]);

    $this->server->gatherServerMetadata();
    $server = $this->server->fresh();

    expect($server->detectedGpus())->toBe($expectedGpus)
        ->and($server->server_metadata['nvidia_container_toolkit'])->toBe($expectedToolkit)
        ->and($server->server_metadata['cpus'])->toBe(8);
})->with([
    'two gpus' => [
        ['0, GPU-1a2b, 81559, NVIDIA H100 80GB HBM3', '1, GPU-3c4d, 24564, NVIDIA GeForce RTX 4090, Founders'],
        ['/usr/bin/nvidia-container-runtime-hook', '/usr/bin/nvidia-ctk'],
        [
            ['index' => '0', 'uuid' => 'GPU-1a2b', 'memory_mb' => 81559, 'name' => 'NVIDIA H100 80GB HBM3'],
            ['index' => '1', 'uuid' => 'GPU-3c4d', 'memory_mb' => 24564, 'name' => 'NVIDIA GeForce RTX 4090, Founders'],
        ],
        true,
    ],
    'driver installed without a working gpu' => [
        ['NVIDIA-SMI has failed because it could not communicate with the NVIDIA driver.'],
        [],
        [],
        false,
    ],
    'no nvidia-smi' => [[], [], [], false],
]);

it('returns null detected GPUs when GPUs were never checked', function () {
    $this->server->update(['server_metadata' => ['os' => 'Ubuntu 24.04 LTS']]);

    expect($this->server->fresh()->detectedGpus())->toBeNull();
});
