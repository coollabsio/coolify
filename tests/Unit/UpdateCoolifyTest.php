<?php

use App\Actions\Server\UpdateCoolify;
use App\Livewire\Settings\Updates;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Once;
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
        'update_channel' => 'stable',
        'auto_update_scope' => 'minor',
    ], $settings));
    Once::flush();
}

afterEach(function () {
    Mockery::close();
});

it('has UpdateCoolify action class', function () {
    expect(class_exists(UpdateCoolify::class))->toBeTrue();
});

it('does not downgrade when cached metadata is older than the running version', function () {
    updateCoolifyTestCreateRootServerAndSettings(['new_version_available' => true]);

    // CDN fails
    Http::fake(['*' => Http::response(null, 500)]);

    // Mock cache returning older version
    Cache::shouldReceive('remember')
        ->andReturn(['coolify' => ['v4' => ['version' => '4.0.5']]]);

    config(['constants.coolify.version' => '4.0.10']);

    $action = new UpdateCoolify;

    Log::shouldReceive('warning')
        ->once()
        ->with('Failed to fetch fresh version from CDN, using validated cache', Mockery::type('array'));

    $action->handle(manual_update: false);

    expect($action->latestVersion)->toBe('4.0.10')
        ->and(Activity::query()->count())->toBe(0);
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

it('uses newer cached metadata when a successful CDN response is stale', function () {
    Queue::fake();
    config([
        'app.env' => 'testing',
        'constants.coolify.version' => '4.0.5',
        'constants.coolify.helper_version' => '1.0.15',
        'constants.coolify.upgrade_script_url' => 'https://cdn.example.com/upgrade.sh',
        'constants.ssh.mux_enabled' => false,
    ]);
    updateCoolifyTestCreateRootServerAndSettings(['new_version_available' => true]);

    Cache::shouldReceive('remember')->andReturn([
        'coolify' => ['v4' => ['version' => '4.0.10']],
    ]);
    Http::fake(['*' => Http::response([
        'coolify' => ['v4' => ['version' => '4.0.6']],
    ])]);

    $action = new UpdateCoolify;
    $action->handle(manual_update: true);

    expect($action->latestVersion)->toBe('4.0.10')
        ->and(Activity::query()->latest('id')->first()?->getExtraProperty('command'))
        ->toContain("'4.0.10'");
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

    expect(Activity::query()->latest('id')->first()?->getExtraProperty('command'))->toBe(
        "curl -fsSL https://cdn.example.com/upgrade.sh -o /data/coolify/source/upgrade.sh\n".
        "bash /data/coolify/source/upgrade.sh '4.0.10' '1.0.14' 'ghcr.io'"
    );
});

it('uses the RC version and RC upgrade script for a manual RC update', function () {
    Queue::fake();
    config([
        'app.env' => 'testing',
        'constants.coolify.version' => '4.3.10',
        'constants.coolify.helper_version' => '1.0.15',
        'constants.coolify.rc_upgrade_script_url' => 'https://cdn.example.com/coolify-rc/upgrade.sh',
        'constants.ssh.mux_enabled' => false,
    ]);

    updateCoolifyTestCreateRootServerAndSettings(['update_channel' => 'rc']);

    Http::fake(['*' => Http::response([
        'coolify' => [
            'v4' => ['version' => '4.3.10'],
            'rc' => ['version' => '4.4-rc.1'],
        ],
    ])]);

    (new UpdateCoolify)->handle(manual_update: true);

    expect(Activity::query()->latest('id')->first()?->getExtraProperty('command'))
        ->toContain('https://cdn.example.com/coolify-rc/upgrade.sh')
        ->toContain("'4.4-rc.1'");
});

it('never installs an RC during automatic updates', function () {
    Queue::fake();
    config([
        'app.env' => 'testing',
        'constants.coolify.version' => '4.3.10',
        'constants.coolify.helper_version' => '1.0.15',
        'constants.coolify.upgrade_script_url' => 'https://cdn.example.com/coolify/upgrade.sh',
        'constants.ssh.mux_enabled' => false,
    ]);

    updateCoolifyTestCreateRootServerAndSettings(['update_channel' => 'rc']);

    Http::fake(['*' => Http::response([
        'coolify' => [
            'v4' => ['version' => '4.3.11'],
            'rc' => ['version' => '4.4-rc.1'],
        ],
    ])]);

    (new UpdateCoolify)->handle();

    expect(Activity::query()->latest('id')->first()?->getExtraProperty('command'))
        ->toContain("'4.3.11'")
        ->not->toContain('4.4-rc.1');
});

it('keeps patch-only automatic updates on the installed minor line', function () {
    Queue::fake();
    config([
        'app.env' => 'testing',
        'constants.coolify.version' => '4.3.10',
        'constants.coolify.helper_version' => '1.0.15',
        'constants.coolify.upgrade_script_url' => 'https://cdn.example.com/coolify/upgrade.sh',
        'constants.ssh.mux_enabled' => false,
    ]);

    updateCoolifyTestCreateRootServerAndSettings(['auto_update_scope' => 'patch']);

    Http::fake(['*' => Http::response([
        'coolify' => [
            'v4' => [
                'version' => '4.4.0',
                'minors' => ['4.3' => '4.3.11', '4.4' => '4.4.0'],
            ],
        ],
    ])]);

    (new UpdateCoolify)->handle();

    expect(Activity::query()->latest('id')->first()?->getExtraProperty('command'))
        ->toContain("'4.3.11'")
        ->not->toContain('4.4.0');
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

    expect(Activity::query()->latest('id')->first()?->getExtraProperty('command'))->toBe(
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

it('defaults update preferences to stable and all minor and patch versions', function () {
    config([
        'app.env' => 'local',
        'constants.coolify.self_hosted' => true,
        'constants.coolify.version' => '4.3.10',
    ]);

    updateCoolifyTestCreateRootServerAndSettings();
    $rootTeam = Team::findOrFail(0);
    $user = User::factory()->create();
    $rootTeam->members()->attach($user->id, ['role' => 'admin']);
    $this->actingAs($user);
    session(['currentTeam' => ['id' => 0]]);

    Livewire::test(Updates::class)
        ->assertSet('update_channel', 'stable')
        ->assertSet('auto_update_scope', 'minor');
});

it('saves valid update preferences', function () {
    config([
        'app.env' => 'local',
        'constants.coolify.self_hosted' => true,
        'constants.coolify.version' => '4.3.10',
    ]);

    updateCoolifyTestCreateRootServerAndSettings();
    $rootTeam = Team::findOrFail(0);
    $user = User::factory()->create();
    $rootTeam->members()->attach($user->id, ['role' => 'admin']);
    $this->actingAs($user);
    session(['currentTeam' => ['id' => 0]]);

    Livewire::test(Updates::class)
        ->set('update_channel', 'rc')
        ->call('saveUpdateChannel')
        ->assertDispatched('updateAvailable')
        ->set('auto_update_scope', 'patch')
        ->call('saveAutoUpdateScope');

    $settings = InstanceSettings::findOrFail(0);
    expect($settings->update_channel)->toBe('rc')
        ->and($settings->auto_update_scope)->toBe('patch');
});

it('rejects invalid update preferences', function () {
    config([
        'app.env' => 'local',
        'constants.coolify.self_hosted' => true,
        'constants.coolify.version' => '4.3.10',
    ]);

    updateCoolifyTestCreateRootServerAndSettings();
    $rootTeam = Team::findOrFail(0);
    $user = User::factory()->create();
    $rootTeam->members()->attach($user->id, ['role' => 'admin']);
    $this->actingAs($user);
    session(['currentTeam' => ['id' => 0]]);

    Livewire::test(Updates::class)
        ->set('update_channel', 'nightly')
        ->call('saveUpdateChannel')
        ->assertHasErrors(['update_channel' => ['in']])
        ->set('auto_update_scope', 'major')
        ->call('saveAutoUpdateScope')
        ->assertHasErrors(['auto_update_scope' => ['in']]);
});

it('warns instead of offering a downgrade after switching from RC to stable', function () {
    config([
        'app.env' => 'local',
        'constants.coolify.self_hosted' => true,
        'constants.coolify.version' => '4.4-rc.1',
    ]);

    updateCoolifyTestCreateRootServerAndSettings(['update_channel' => 'stable']);
    $rootTeam = Team::findOrFail(0);
    $user = User::factory()->create();
    $rootTeam->members()->attach($user->id, ['role' => 'admin']);
    $this->actingAs($user);
    session(['currentTeam' => ['id' => 0]]);

    Livewire::test(Updates::class)
        ->assertSet('isWaitingForStable', true)
        ->assertSee('Coolify will not downgrade it');
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

it('uses the database registry for sentinel images', function () {
    $action = file_get_contents(app_path('Actions/Server/StartSentinel.php'));

    expect($action)->toContain("\$image = coolifyRegistryUrl().'/coollabsio/sentinel:'.\$version;");
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

    expect($method->invoke($component, 'ghcr.io'))
        ->toContain("grep -q '^REGISTRY_URL=' /data/coolify/source/.env")
        ->toContain("sed -i 's|^REGISTRY_URL=.*|REGISTRY_URL=ghcr.io|' /data/coolify/source/.env")
        ->toContain("printf '%s\\n' 'REGISTRY_URL=ghcr.io' >> /data/coolify/source/.env");
});

it('does not run a downgrade even with manual update', function () {
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

    $action->handle(manual_update: true);

    expect($action->latestVersion)->toBe('4.0.10')
        ->and(Activity::query()->count())->toBe(0);
});
