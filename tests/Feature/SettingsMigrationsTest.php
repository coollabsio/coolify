<?php

use App\Actions\Migration\DetectIndependentCoolifyInstall;
use App\Actions\Migration\RunInstanceMigration;
use App\Enums\InstanceMigrationStatus;
use App\Livewire\Settings\Migrations;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceMigration;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Once;
use Livewire\Livewire;
use Lorisleiva\Actions\Decorators\JobDecorator;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
});

function createInstanceAdminForMigrations(): User
{
    $rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0]);
    InstanceSettings::forceCreate(['id' => 0]);
    Once::flush();

    $user = User::factory()->create();
    $rootTeam->members()->attach($user->id, ['role' => 'admin']);

    test()->actingAs($user);
    session(['currentTeam' => ['id' => $rootTeam->id]]);

    return $user;
}

test('non-admin user is redirected from settings migrations page', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'member']);

    $this->actingAs($user);
    session(['currentTeam' => ['id' => $team->id]]);

    Livewire::test(Migrations::class)
        ->assertRedirect(route('dashboard'));
});

test('instance settings layout includes migrations navigation', function () {
    $layout = file_get_contents(resource_path('views/components/settings/layout.blade.php'));

    expect($layout)
        ->toContain("'label' => 'Migrations', 'route' => 'settings.migrations'");
});

test('migrations page stacks resource copy sections with vertical gap', function () {
    $view = file_get_contents(resource_path('views/livewire/settings/migrations.blade.php'));

    expect($view)
        ->toContain('<div class="flex min-w-0 flex-col gap-6">')
        ->not->toContain('pb-2 text-sm text-neutral-500');
});

test('instance admin can access settings migrations page', function () {
    createInstanceAdminForMigrations();

    Livewire::test(Migrations::class)
        ->assertOk()
        ->assertNoRedirect()
        ->assertSee('Migrations')
        ->assertSee('Resource copy')
        ->assertSee('Instance migration')
        ->assertSee('Source server')
        ->assertSee('Target server')
        ->assertSee('Do not install Coolify on the target')
        ->assertSee('coolify-proxy')
        ->assertDontSee('Source URL');
});

test('instance migration tab shows full migrate copy', function () {
    createInstanceAdminForMigrations();

    Livewire::test(Migrations::class)
        ->call('setMode', 'instance')
        ->assertSet('mode', 'instance')
        ->assertSee('Full instance migration')
        ->assertSee('Use a fresh VM')
        ->assertSee('Target IP / hostname')
        ->assertDontSee('Do not install Coolify on the target');
});

test('instance migration requires target fields', function () {
    createInstanceAdminForMigrations();

    Livewire::test(Migrations::class)
        ->call('setMode', 'instance')
        ->call('startInstanceMigration')
        ->assertHasErrors(['instanceTargetIp', 'instancePrivateKeyId']);
});

test('starting instance migration queues the job and shows progress steps', function () {
    Bus::fake();
    createInstanceAdminForMigrations();
    $team = currentTeam();
    $key = PrivateKey::factory()->create(['team_id' => $team->id, 'name' => 'Target Key']);

    $component = Livewire::test(Migrations::class)
        ->call('setMode', 'instance')
        ->set('instanceTargetIp', '10.0.0.50')
        ->set('instanceTargetUser', 'root')
        ->set('instanceTargetPort', 22)
        ->set('instancePrivateKeyId', (string) $key->id)
        ->call('startInstanceMigration')
        ->assertHasNoErrors()
        ->assertSet('instanceStatus', 'pending')
        ->assertSet('instanceMigrationRunning', true)
        ->assertSee('Instance migration progress')
        ->assertSee('Packaging backup')
        ->assertSee('Installing Coolify')
        ->assertSee('Restoring Coolify database')
        ->assertSee('Copying volumes');

    expect($component->get('instanceSteps'))->not->toBeEmpty();

    Bus::assertDispatched(function (JobDecorator $job): bool {
        return $job->decorates(RunInstanceMigration::class);
    });
});

test('refresh instance migration updates progress from database', function () {
    createInstanceAdminForMigrations();
    $team = currentTeam();
    $key = PrivateKey::factory()->create(['team_id' => $team->id]);
    $migration = InstanceMigration::factory()->create([
        'team_id' => $team->id,
        'target_private_key_id' => $key->id,
        'created_by_user_id' => auth()->id(),
        'status' => InstanceMigrationStatus::Pending,
    ]);
    $migration->markPhase(InstanceMigrationStatus::Installing, 'Installing Coolify on target');

    Livewire::test(Migrations::class)
        ->call('setMode', 'instance')
        ->set('instanceMigrationId', $migration->id)
        ->call('refreshInstanceMigration')
        ->assertSet('instanceStatus', 'installing')
        ->assertSee('Installing Coolify')
        ->assertSee('Installing Coolify on target');
});

test('selecting a source server lists its resources', function () {
    createInstanceAdminForMigrations();
    $team = currentTeam();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $source = Server::factory()->create(['team_id' => $team->id, 'private_key_id' => $privateKey->id, 'name' => 'Source']);
    $source->settings()->update(['is_reachable' => true, 'is_usable' => true, 'is_build_server' => false]);
    $destination = StandaloneDocker::where('server_id', $source->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first()
        ?? Environment::factory()->create(['project_id' => $project->id]);
    $application = Application::factory()->create([
        'name' => 'migratable-app',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    Livewire::test(Migrations::class)
        ->set('sourceServerUuid', $source->uuid)
        ->assertSet('phase', 'discovered')
        ->assertSee('migratable-app')
        ->assertSet('selectedResourceUuids', [$application->uuid]);
});

test('start migration clones selected resources to the target server', function () {
    Bus::fake();
    createInstanceAdminForMigrations();
    $team = currentTeam();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $source = Server::factory()->create(['team_id' => $team->id, 'private_key_id' => $privateKey->id, 'name' => 'Source']);
    $target = Server::factory()->create(['team_id' => $team->id, 'private_key_id' => $privateKey->id, 'name' => 'Target']);
    $source->settings()->update(['is_reachable' => true, 'is_usable' => true, 'is_build_server' => false]);
    $target->settings()->update(['is_reachable' => true, 'is_usable' => true, 'is_build_server' => false]);
    $sourceDestination = StandaloneDocker::where('server_id', $source->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first()
        ?? Environment::factory()->create(['project_id' => $project->id]);
    $application = Application::factory()->create([
        'name' => 'source-app',
        'environment_id' => $environment->id,
        'destination_id' => $sourceDestination->id,
        'destination_type' => $sourceDestination->getMorphClass(),
    ]);

    Livewire::test(Migrations::class)
        ->set('sourceServerUuid', $source->uuid)
        ->assertSet('phase', 'discovered')
        ->set('targetServerUuid', $target->uuid)
        ->set('cloneVolumeData', false)
        ->call('startMigration')
        ->assertHasNoErrors()
        ->assertSet('phase', 'completed')
        ->assertSet('migratedCount', 1);

    $cloned = Application::where('environment_id', $environment->id)
        ->where('id', '!=', $application->id)
        ->first();

    expect($cloned)->not->toBeNull()
        ->and($cloned->destination_id)->not->toBe($sourceDestination->id);
});

test('requires a different target server', function () {
    createInstanceAdminForMigrations();
    $team = currentTeam();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $source = Server::factory()->create(['team_id' => $team->id, 'private_key_id' => $privateKey->id]);
    $source->settings()->update(['is_build_server' => false]);
    $destination = StandaloneDocker::where('server_id', $source->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first()
        ?? Environment::factory()->create(['project_id' => $project->id]);
    Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    Livewire::test(Migrations::class)
        ->set('sourceServerUuid', $source->uuid)
        ->set('targetServerUuid', $source->uuid)
        ->call('startMigration')
        ->assertHasErrors(['targetServerUuid']);
});

test('blocks migration when the target is not validated', function () {
    createInstanceAdminForMigrations();
    $team = currentTeam();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $source = Server::factory()->create(['team_id' => $team->id, 'private_key_id' => $privateKey->id, 'name' => 'Source']);
    $target = Server::factory()->create(['team_id' => $team->id, 'private_key_id' => $privateKey->id, 'name' => 'Target']);
    $source->settings()->update(['is_reachable' => true, 'is_usable' => true, 'is_build_server' => false]);
    $target->settings()->update(['is_reachable' => false, 'is_usable' => false, 'is_build_server' => false]);
    $sourceDestination = StandaloneDocker::where('server_id', $source->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first()
        ?? Environment::factory()->create(['project_id' => $project->id]);
    Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $sourceDestination->id,
        'destination_type' => $sourceDestination->getMorphClass(),
    ]);

    Livewire::test(Migrations::class)
        ->set('sourceServerUuid', $source->uuid)
        ->set('targetServerUuid', $target->uuid)
        ->assertSet('targetBlockReason', 'not_ready')
        ->assertSee('Target server is not ready')
        ->call('startMigration')
        ->assertSet('phase', 'failed')
        ->assertSet('migratedCount', 0);
});

test('blocks migration when the target already has coolify installed', function () {
    $this->mock(DetectIndependentCoolifyInstall::class, function ($mock) {
        $mock->shouldReceive('handle')->andReturn(true);
    });

    createInstanceAdminForMigrations();
    $team = currentTeam();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $source = Server::factory()->create(['team_id' => $team->id, 'private_key_id' => $privateKey->id, 'name' => 'Source']);
    $target = Server::factory()->create(['team_id' => $team->id, 'private_key_id' => $privateKey->id, 'name' => 'Target']);
    $source->settings()->update(['is_reachable' => true, 'is_usable' => true, 'is_build_server' => false]);
    $target->settings()->update(['is_reachable' => true, 'is_usable' => true, 'is_build_server' => false]);
    $sourceDestination = StandaloneDocker::where('server_id', $source->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first()
        ?? Environment::factory()->create(['project_id' => $project->id]);
    Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $sourceDestination->id,
        'destination_type' => $sourceDestination->getMorphClass(),
    ]);

    Livewire::test(Migrations::class)
        ->set('sourceServerUuid', $source->uuid)
        ->set('targetServerUuid', $target->uuid)
        ->assertSet('targetBlockReason', 'independent_coolify')
        ->assertSee('Coolify is already installed on the target')
        ->call('startMigration')
        ->assertSet('phase', 'failed')
        ->assertSet('migratedCount', 0);
});
