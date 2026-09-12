<?php

use App\Actions\Server\StartSentinel;
use App\Enums\ServerMode;
use App\Jobs\CheckAndStartSentinelJob;
use App\Jobs\ServerConnectionCheckJob;
use App\Livewire\Server\ValidateAndInstall;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('ssh-keys');
});

function createServerForModeValidation(array $attributes = []): Server
{
    $team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);

    return Server::factory()->create(array_merge([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
        'ip' => '203.0.113.10',
    ], $attributes));
}

it('stores an explicit legacy mode for existing servers', function () {
    $server = createServerForModeValidation()->fresh();

    expect(Schema::hasColumn('servers', 'mode'))->toBeTrue()
        ->and($server->getRawOriginal('mode'))->toBe('legacy')
        ->and($server->mode->value)->toBe('legacy');
});

it('uses node names for every server mode', function () {
    expect(array_column(ServerMode::cases(), 'value'))->toBe([
        'legacy',
        'node-worker',
        'node-controller-worker',
        'node-controller',
    ]);
});

it('migrates old v5 mode names to node names', function () {
    $team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);

    $servers = collect(['v5-worker', 'v5-combined', 'v5-control-plane'])
        ->map(function (string $mode) use ($team, $privateKey): Server {
            $server = Server::factory()->create([
                'team_id' => $team->id,
                'private_key_id' => $privateKey->id,
            ]);
            Server::query()->whereKey($server)->update(['mode' => $mode]);

            return $server;
        });

    $migration = require database_path('migrations/2026_09_12_121547_rename_server_modes_to_node_modes.php');
    $migration->up();

    expect($servers->map(fn (Server $server) => Server::query()->whereKey($server)->value('mode')->value)->all())->toBe([
        'node-worker',
        'node-controller-worker',
        'node-controller',
    ]);
});

it('uses podman instead of docker for node worker connection checks', function () {
    $server = createServerForModeValidation(['mode' => 'node-worker'])->fresh();

    Process::fake(['*' => Process::result(output: '{"host":{"arch":"amd64"}}')]);

    (new ServerConnectionCheckJob($server, disableMux: false))->handle();

    expect($server->mode->value)->toBe('node-worker')
        ->and($server->usesPodman())->toBeTrue()
        ->and($server->settings->fresh()->is_usable)->toBeTrue();
});

it('shows podman validation checkpoints for node workers', function () {
    $server = createServerForModeValidation(['mode' => 'node-worker'])->fresh();

    Livewire::test(ValidateAndInstall::class, ['server' => $server])
        ->set('uptime', 100)
        ->set('supported_os_type', true)
        ->set('prerequisites_installed', true)
        ->set('docker_installed', true)
        ->set('docker_compose_installed', true)
        ->set('docker_version', true)
        ->assertSee('Podman is installed')
        ->assertSee('Podman API is available')
        ->assertDontSee('Docker Compose is installed');
});

it('never starts the legacy container Sentinel on nodes', function () {
    $server = createServerForModeValidation(['mode' => 'node-worker'])->fresh();
    Process::fake();

    (new CheckAndStartSentinelJob($server))->handle();
    StartSentinel::run($server);

    Process::assertNothingRan();
});
