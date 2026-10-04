<?php

use App\Actions\Server\UpdateCoolify;
use App\Livewire\Settings\Updates;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\ScheduledDatabaseBackup;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use App\Support\RemoteProcessCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Once;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function updateCoolifyTestCreateRootServerAndSettings(array $settings = []): void
{
    Team::factory()->create(['id' => 0]);
    Server::forceCreate([
        'id' => 0,
        'name' => 'localhost',
        'ip' => '127.0.0.1',
        'user' => 'root',
        'team_id' => 0,
        'private_key_id' => 1,
    ]);
    InstanceSettings::forceCreate(array_merge([
        'id' => 0,
        'is_auto_update_enabled' => true,
        'auto_update_frequency' => '0 0 * * *',
        'update_check_frequency' => '0 * * * *',
    ], $settings));
    Once::flush();
}

function updateCoolifyTestCreateInstanceBackup(): ScheduledDatabaseBackup
{
    Storage::fake('ssh-keys');
    Notification::fake();
    Server::query()->whereKey(0)->update(['private_key_id' => PrivateKey::factory()->create(['team_id' => 0])->id]);
    Server::flushIdentityMap();
    StandalonePostgresql::forceCreate([
        'id' => 0,
        'name' => 'coolify-db',
        'postgres_password' => 'password',
        'destination_type' => StandaloneDocker::class,
        'destination_id' => 0,
    ]);

    return ScheduledDatabaseBackup::create([
        'frequency' => '0 0 * * *',
        'database_id' => 0,
        'database_type' => StandalonePostgresql::class,
        'team_id' => 0,
    ]);
}

afterEach(function () {
    Mockery::close();
});

it('validates cache against running version before fallback', function () {
    updateCoolifyTestCreateRootServerAndSettings();

    // CDN fails
    Http::fake(['*' => Http::response(null, 500)]);

    // Mock cache returning older version
    Cache::shouldReceive('remember')
        ->andReturn(['coolify' => ['v4' => ['version' => '4.0.5']]]);

    config(['constants.coolify.version' => '4.0.10']);

    $action = new UpdateCoolify;

    // Should throw exception - cache is older than running
    try {
        $action->handle(manual_update: false);
        expect(false)->toBeTrue('Expected exception was not thrown');
    } catch (Exception $e) {
        expect($e->getMessage())->toContain('cache version');
        expect($e->getMessage())->toContain('4.0.5');
        expect($e->getMessage())->toContain('4.0.10');
    }
});

it('uses validated cache when CDN fails and cache is newer', function () {
    updateCoolifyTestCreateRootServerAndSettings();
    Queue::fake();
    config(['constants.ssh.mux_enabled' => false]);

    // CDN fails
    Http::fake(['*' => Http::response(null, 500)]);

    // Cache has newer version than current
    Cache::shouldReceive('remember')
        ->andReturn(['coolify' => ['v4' => ['version' => '4.0.10']]]);

    config(['constants.coolify.version' => '4.0.5']);

    $action = new UpdateCoolify;

    Log::shouldReceive('warning')
        ->once()
        ->with('Failed to fetch fresh version from CDN, using validated cache', Mockery::type('array'));

    // Should not throw - cache (4.0.10) > running (4.0.5)
    $action->handle(manual_update: false);

    expect($action->latestVersion)->toBe('4.0.10');
});

it('passes the saved registry URL to the upgrade script command', function () {
    Queue::fake();
    config([
        'app.env' => 'testing',
        'constants.coolify.version' => '4.0.9',
        'constants.coolify.helper_version' => '1.0.14',
        'constants.coolify.upgrade_script_url' => 'https://cdn.example.com/upgrade.sh',
        'constants.ssh.mux_enabled' => false,
    ]);

    updateCoolifyTestCreateRootServerAndSettings([
        'is_auto_update_enabled' => true,
        'docker_registry_url' => 'ghcr.io',
    ]);

    Http::fake([
        '*' => Http::response([
            'coolify' => ['v4' => ['version' => '4.0.10']],
        ], 200),
    ]);

    (new UpdateCoolify)->handle();

    expect(RemoteProcessCommand::read(Activity::query()->latest('id')->first()))->toBe(
        "curl -fsSL https://cdn.example.com/upgrade.sh -o /data/coolify/source/upgrade.sh\n".
        "bash /data/coolify/source/upgrade.sh '4.0.10' '1.0.14' 'ghcr.io'"
    );
});

it('falls back to docker io for the upgrade script command when no registry is saved', function () {
    Queue::fake();
    config([
        'app.env' => 'testing',
        'constants.coolify.version' => '4.0.9',
        'constants.coolify.helper_version' => '1.0.14',
        'constants.coolify.registry_url' => 'ghcr.io',
        'constants.coolify.upgrade_script_url' => 'https://cdn.example.com/upgrade.sh',
        'constants.ssh.mux_enabled' => false,
    ]);

    updateCoolifyTestCreateRootServerAndSettings([
        'is_auto_update_enabled' => true,
    ]);

    Http::fake([
        '*' => Http::response([
            'coolify' => ['v4' => ['version' => '4.0.10']],
        ], 200),
    ]);

    (new UpdateCoolify)->handle();

    expect(RemoteProcessCommand::read(Activity::query()->latest('id')->first()))->toBe(
        "curl -fsSL https://cdn.example.com/upgrade.sh -o /data/coolify/source/upgrade.sh\n".
        "bash /data/coolify/source/upgrade.sh '4.0.10' '1.0.14' 'docker.io'"
    );
});

it('defaults the registry setting to docker io when no registry is saved', function () {
    config([
        'app.env' => 'testing',
        'constants.coolify.registry_url' => 'ghcr.io',
        'constants.coolify.self_hosted' => true,
    ]);

    updateCoolifyTestCreateRootServerAndSettings();

    $rootTeam = Team::findOrFail(0);
    $user = User::factory()->create();
    $rootTeam->members()->attach($user->id, ['role' => 'admin']);

    $this->actingAs($user);
    session(['currentTeam' => ['id' => $rootTeam->id]]);

    Livewire::test(Updates::class)
        ->assertSet('docker_registry_url', 'docker.io');
});

it('uses the database registry for helper images when the configured helper image is default', function () {
    config([
        'constants.coolify.registry_url' => 'ghcr.io',
        'constants.coolify.helper_image' => 'ghcr.io/coollabsio/coolify-helper',
    ]);

    updateCoolifyTestCreateRootServerAndSettings([
        'docker_registry_url' => 'docker.io',
    ]);

    expect(coolifyRegistryUrl())->toBe('docker.io')
        ->and(coolifyHelperImage())->toBe('docker.io/coollabsio/coolify-helper');
});

it('preserves an explicit custom helper image override', function () {
    config([
        'constants.coolify.registry_url' => 'docker.io',
        'constants.coolify.helper_image' => 'registry.example.com/custom/helper',
    ]);

    updateCoolifyTestCreateRootServerAndSettings([
        'docker_registry_url' => 'ghcr.io',
    ]);

    expect(coolifyHelperImage())->toBe('registry.example.com/custom/helper');
});

it('rejects invalid registry values and does not sync them', function () {
    Process::fake();
    config([
        'app.env' => 'testing',
        'constants.coolify.registry_url' => 'docker.io',
    ]);

    updateCoolifyTestCreateRootServerAndSettings([
        'is_auto_update_enabled' => true,
        'auto_update_frequency' => '0 0 * * *',
        'update_check_frequency' => '0 * * * *',
        'docker_registry_url' => 'docker.io',
    ]);

    $rootTeam = Team::findOrFail(0);
    $user = User::factory()->create();
    $rootTeam->members()->attach($user->id, ['role' => 'admin']);

    $this->actingAs($user);
    session(['currentTeam' => ['id' => $rootTeam->id]]);

    Livewire::test(Updates::class)
        ->set('docker_registry_url', 'ghcr.io; touch /tmp/pwned')
        ->call('submit')
        ->assertHasErrors(['docker_registry_url' => ['in']]);

    expect(InstanceSettings::findOrFail(0)->docker_registry_url)->toBe('docker.io');
    Process::assertDidntRun(fn () => true);
});

it('does not save registry changes when syncing the env file fails', function () {
    config([
        'app.env' => 'testing',
        'constants.coolify.registry_url' => 'docker.io',
        'constants.coolify.self_hosted' => true,
    ]);

    updateCoolifyTestCreateRootServerAndSettings([
        'is_auto_update_enabled' => true,
        'auto_update_frequency' => '0 0 * * *',
        'update_check_frequency' => '0 * * * *',
        'docker_registry_url' => 'docker.io',
    ]);

    $rootTeam = Team::findOrFail(0);
    $user = User::factory()->create();
    $rootTeam->members()->attach($user->id, ['role' => 'admin']);

    $this->actingAs($user);
    session(['currentTeam' => ['id' => $rootTeam->id]]);

    $component = new class extends Updates
    {
        protected function syncRegistryUrlToEnv(string $registryUrl): void
        {
            throw new RuntimeException('sync failed');
        }
    };
    $component->settings = InstanceSettings::findOrFail(0);
    $component->auto_update_frequency = '0 0 * * *';
    $component->update_check_frequency = '0 * * * *';
    $component->is_auto_update_enabled = true;
    $component->docker_registry_url = 'ghcr.io';

    $component->instantSave();

    expect(InstanceSettings::findOrFail(0)->docker_registry_url)->toBe('docker.io');
});

it('appends registry url to env file when the key is missing', function () {
    $component = new Updates;
    $method = new ReflectionMethod(Updates::class, 'registryEnvSyncCommand');

    $server = new Server;
    $server->user = 'cooluser';
    $lines = parseCommandsByLineForSudo(collect($method->invoke($component, 'ghcr.io')), $server);

    // Each branch runs through sudo for a non-root SSH user; nothing is opened by the SSH user's shell.
    expect($lines)->toBe([
        "if sudo grep -q '^REGISTRY_URL=' /data/coolify/source/.env; then",
        "sudo     sed -i 's|^REGISTRY_URL=.*|REGISTRY_URL=ghcr.io|' /data/coolify/source/.env",
        'else',
        "sudo     printf '%s\\n' 'REGISTRY_URL=ghcr.io' | sudo tee -a /data/coolify/source/.env > /dev/null",
        'fi',
    ]);
});

it('prevents downgrade even with manual update', function () {
    updateCoolifyTestCreateRootServerAndSettings();

    // CDN returns older version
    Http::fake([
        '*' => Http::response([
            'coolify' => ['v4' => ['version' => '4.0.0']],
        ], 200),
    ]);

    // Current version is newer
    config(['constants.coolify.version' => '4.0.10']);

    $action = new UpdateCoolify;

    Log::shouldReceive('error')
        ->once()
        ->with('Downgrade prevented', Mockery::type('array'));

    // Should throw exception even for manual updates
    try {
        $action->handle(manual_update: true);
        expect(false)->toBeTrue('Expected exception was not thrown');
    } catch (Exception $e) {
        expect($e->getMessage())->toContain('Cannot downgrade');
        expect($e->getMessage())->toContain('4.0.10');
        expect($e->getMessage())->toContain('4.0.0');
    }
});

it('runs the instance backup before the update when it is enabled', function (string $env, bool $manualUpdate, bool $isBackupBeforeUpdateEnabled) {
    Sleep::fake();
    Queue::fake();
    config([
        'app.env' => $env,
        'constants.coolify.version' => '4.0.9',
        'constants.ssh.mux_enabled' => false,
    ]);
    Http::fake(['*' => Http::response(['coolify' => ['v4' => ['version' => '4.0.10']]], 200)]);

    updateCoolifyTestCreateRootServerAndSettings(['is_backup_before_update_enabled' => $isBackupBeforeUpdateEnabled]);
    updateCoolifyTestCreateInstanceBackup();
    Process::fake(['*du -b*' => '128', '*' => '']);

    (new UpdateCoolify)->handle(manual_update: $manualUpdate);

    $isBackupBeforeUpdateEnabled
        ? Process::assertRan(fn ($process) => str_contains($process->command, 'pg_dump'))
        : Process::assertNotRan(fn ($process) => str_contains($process->command, 'pg_dump'));
})->with([
    'manual update' => ['testing', true, true],
    'automatic update' => ['testing', false, true],
    'development' => ['local', true, true],
    'disabled' => ['testing', true, false],
]);

it('does not start the update when the instance backup fails', function () {
    Queue::fake();
    config([
        'app.env' => 'testing',
        'constants.coolify.version' => '4.0.9',
        'constants.ssh.mux_enabled' => false,
    ]);
    Http::fake(['*' => Http::response(['coolify' => ['v4' => ['version' => '4.0.10']]], 200)]);

    updateCoolifyTestCreateRootServerAndSettings();
    updateCoolifyTestCreateInstanceBackup();
    Process::fake(['*pg_dump*' => Process::result(exitCode: 1), '*' => '']);

    expect(fn () => (new UpdateCoolify)->handle(manual_update: true))
        ->toThrow(Exception::class, 'The database backup failed, so the update was not started.')
        ->and(Activity::count())->toBe(0);
});
