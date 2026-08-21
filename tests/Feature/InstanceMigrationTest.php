<?php

use App\Actions\Migration\CollectResourceVolumes;
use App\Actions\Migration\ConsolidateResourcesToLocalhost;
use App\Actions\Migration\ReassignResourceToDestination;
use App\Enums\InstanceMigrationStatus;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceMigration;
use App\Models\InstanceSettings;
use App\Models\LocalPersistentVolume;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
});

function createTeamContextForInstanceMigration(): Team
{
    $rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0]);
    InstanceSettings::forceCreate(['id' => 0]);
    Once::flush();

    $user = User::factory()->create();
    $rootTeam->members()->attach($user->id, ['role' => 'admin']);
    test()->actingAs($user);
    session(['currentTeam' => ['id' => $rootTeam->id]]);

    return $rootTeam;
}

test('instance migration factory and phase helpers work', function () {
    createTeamContextForInstanceMigration();
    $key = PrivateKey::factory()->create(['team_id' => currentTeam()->id]);
    $migration = InstanceMigration::factory()->create([
        'team_id' => currentTeam()->id,
        'target_private_key_id' => $key->id,
        'created_by_user_id' => auth()->id(),
    ]);

    $migration->markPhase(InstanceMigrationStatus::Packaging, 'dump');
    expect($migration->fresh()->status)->toBe(InstanceMigrationStatus::Packaging)
        ->and($migration->fresh()->phases)->toHaveCount(1);

    $migration->markCompleted('http://10.0.0.2:8000');
    expect($migration->fresh()->status)->toBe(InstanceMigrationStatus::Completed)
        ->and($migration->fresh()->dashboard_url)->toBe('http://10.0.0.2:8000')
        ->and($migration->fresh()->phases)->toHaveCount(2)
        ->and($migration->fresh()->progressPercent())->toBe(100);
});

test('instance migration step states show active packaging and pending later steps', function () {
    createTeamContextForInstanceMigration();
    $key = PrivateKey::factory()->create(['team_id' => currentTeam()->id]);
    $migration = InstanceMigration::factory()->create([
        'team_id' => currentTeam()->id,
        'target_private_key_id' => $key->id,
        'created_by_user_id' => auth()->id(),
    ]);

    $migration->markPhase(InstanceMigrationStatus::Packaging, 'Creating backup');
    $steps = $migration->fresh()->stepStates();

    expect($steps[0]['state'])->toBe('active')
        ->and($steps[0]['label'])->toBe('Packaging backup')
        ->and($steps[1]['state'])->toBe('pending')
        ->and($migration->fresh()->progressPercent())->toBeGreaterThan(0)
        ->and($migration->fresh()->progressPercent())->toBeLessThan(100);
});

test('instance migration failed step is marked on the last successful phase', function () {
    createTeamContextForInstanceMigration();
    $key = PrivateKey::factory()->create(['team_id' => currentTeam()->id]);
    $migration = InstanceMigration::factory()->create([
        'team_id' => currentTeam()->id,
        'target_private_key_id' => $key->id,
        'created_by_user_id' => auth()->id(),
    ]);

    $migration->markPhase(InstanceMigrationStatus::Packaging, 'ok');
    $migration->markPhase(InstanceMigrationStatus::Installing, 'Installing Coolify');
    $migration->markFailed('syntax error near unexpected token apt-get');

    $steps = $migration->fresh()->stepStates();

    expect($migration->fresh()->status)->toBe(InstanceMigrationStatus::Failed)
        ->and($steps[0]['state'])->toBe('done')
        ->and($steps[1]['state'])->toBe('failed')
        ->and($steps[2]['state'])->toBe('pending');
});

test('reassign resource moves application destination without cloning uuid', function () {
    Bus::fake();
    createTeamContextForInstanceMigration();
    $team = currentTeam();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $source = Server::factory()->create(['team_id' => $team->id, 'private_key_id' => $privateKey->id]);
    $target = Server::factory()->create(['team_id' => $team->id, 'private_key_id' => $privateKey->id]);
    $source->settings()->update(['is_reachable' => true, 'is_usable' => true]);
    $target->settings()->update(['is_reachable' => true, 'is_usable' => true]);
    $sourceDestination = StandaloneDocker::where('server_id', $source->id)->firstOrFail();
    $targetDestination = StandaloneDocker::where('server_id', $target->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first()
        ?? Environment::factory()->create(['project_id' => $project->id]);
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $sourceDestination->id,
        'destination_type' => $sourceDestination->getMorphClass(),
    ]);
    $uuid = $application->uuid;

    ReassignResourceToDestination::run($application, $targetDestination, false);

    $application->refresh();
    expect($application->uuid)->toBe($uuid)
        ->and($application->destination_id)->toBe($targetDestination->id)
        ->and(Application::where('uuid', $uuid)->count())->toBe(1);
});

test('collect resource volumes includes service child volumes', function () {
    createTeamContextForInstanceMigration();
    $team = currentTeam();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create(['team_id' => $team->id, 'private_key_id' => $privateKey->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first()
        ?? Environment::factory()->create(['project_id' => $project->id]);

    $service = Service::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'server_id' => $server->id,
        'docker_compose_raw' => 'services: { web: { image: nginx } }',
    ]);

    $serviceApp = ServiceApplication::create([
        'service_id' => $service->id,
        'name' => 'web',
        'human_name' => 'web',
        'exclude_from_status' => false,
    ]);

    LocalPersistentVolume::create([
        'name' => 'svc-vol-1',
        'mount_path' => '/data',
        'resource_id' => $serviceApp->id,
        'resource_type' => $serviceApp->getMorphClass(),
    ]);

    $volumes = CollectResourceVolumes::run($service->fresh());
    expect($volumes)->toHaveCount(1)
        ->and($volumes[0]->name)->toBe('svc-vol-1');
});

test('consolidate orders databases before services and applications', function () {
    Bus::fake();
    createTeamContextForInstanceMigration();
    $team = currentTeam();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);

    $localhost = Server::find(0);
    if (! $localhost) {
        $localhost = new Server;
        $localhost->forceFill([
            'id' => 0,
            'name' => 'localhost',
            'ip' => 'host.docker.internal',
            'user' => 'root',
            'port' => 22,
            'team_id' => $team->id,
            'private_key_id' => $privateKey->id,
        ]);
        $localhost->save();
    } else {
        $localhost->update(['private_key_id' => $privateKey->id, 'team_id' => $team->id]);
    }
    $localhost->settings()->update(['is_reachable' => true, 'is_usable' => true, 'is_build_server' => false]);

    $remote = Server::factory()->create(['team_id' => $team->id, 'private_key_id' => $privateKey->id, 'name' => 'Remote']);
    $remote->settings()->update(['is_reachable' => true, 'is_usable' => true]);
    $remoteDestination = StandaloneDocker::where('server_id', $remote->id)->firstOrFail();
    $localDestination = StandaloneDocker::where('server_id', 0)->firstOrFail();

    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first()
        ?? Environment::factory()->create(['project_id' => $project->id]);

    $db = StandalonePostgresql::create([
        'name' => 'remote-db',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'app',
        'environment_id' => $environment->id,
        'destination_id' => $remoteDestination->id,
        'destination_type' => $remoteDestination->getMorphClass(),
    ]);
    $app = Application::factory()->create([
        'name' => 'remote-app',
        'environment_id' => $environment->id,
        'destination_id' => $remoteDestination->id,
        'destination_type' => $remoteDestination->getMorphClass(),
    ]);

    $results = ConsolidateResourcesToLocalhost::run($team->id, false);
    $migrated = collect($results)->where('status', 'migrated')->pluck('name')->all();

    expect($migrated)->toContain('remote-db')
        ->and($migrated)->toContain('remote-app');

    expect($db->fresh()->destination_id)->toBe($localDestination->id)
        ->and($app->fresh()->destination_id)->toBe($localDestination->id);
});
