<?php

use App\Jobs\DeleteResourceJob;
use App\Jobs\ScheduledTaskJob;
use App\Models\Application;
use App\Models\CloudInitScript;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\LocalPersistentVolume;
use App\Models\Project;
use App\Models\ScheduledTask;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'cache.default' => 'array',
        'session.driver' => 'array',
        'queue.default' => 'sync',
        'app.maintenance.driver' => 'file',
    ]);

    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(
        ['id' => 0],
        ['is_api_enabled' => true],
    ));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $plainTextToken = Str::random(40);
    $token = $this->user->tokens()->create([
        'name' => 'lifecycle-api-test',
        'token' => hash('sha256', $plainTextToken),
        'abilities' => ['*'],
        'team_id' => $this->team->id,
    ]);
    $this->headers = [
        'Authorization' => 'Bearer '.$token->getKey().'|'.$plainTextToken,
        'Content-Type' => 'application/json',
    ];

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = $this->project->environments()->first()
        ?? Environment::factory()->create(['project_id' => $this->project->id]);

    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'name' => 'source-app',
    ]);
    $this->application->settings->fill([
        'is_container_label_readonly_enabled' => false,
    ])->save();
});

describe('POST /api/v1/applications/{uuid}/clone', function () {
    test('clones an application and returns the new uuid', function () {
        $response = $this->withHeaders($this->headers)
            ->postJson("/api/v1/applications/{$this->application->uuid}/clone", [
                'destination_uuid' => $this->destination->uuid,
                'name' => 'cloned-app',
            ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Application cloned.')
            ->assertJsonStructure(['uuid', 'message']);

        $newUuid = $response->json('uuid');
        expect($newUuid)->not->toBe($this->application->uuid);

        $cloned = Application::where('uuid', $newUuid)->first();
        expect($cloned)->not->toBeNull()
            ->and($cloned->name)->toBe('cloned-app')
            ->and($cloned->environment_id)->toBe($this->application->environment_id)
            ->and($cloned->destination_id)->toBe($this->destination->id);
    });

    test('returns 404 for another team destination', function () {
        $otherTeam = Team::factory()->create();
        $otherServer = Server::factory()->create(['team_id' => $otherTeam->id]);
        $otherDestination = StandaloneDocker::where('server_id', $otherServer->id)->firstOrFail();

        $response = $this->withHeaders($this->headers)
            ->postJson("/api/v1/applications/{$this->application->uuid}/clone", [
                'destination_uuid' => $otherDestination->uuid,
            ]);

        $response->assertNotFound();
    });

    test('returns 404 for another team application', function () {
        $otherTeam = Team::factory()->create();
        $otherServer = Server::factory()->create(['team_id' => $otherTeam->id]);
        $otherDestination = StandaloneDocker::where('server_id', $otherServer->id)->firstOrFail();
        $otherProject = Project::factory()->create(['team_id' => $otherTeam->id]);
        $otherEnvironment = $otherProject->environments()->first()
            ?? Environment::factory()->create(['project_id' => $otherProject->id]);
        $otherApp = Application::factory()->create([
            'environment_id' => $otherEnvironment->id,
            'destination_id' => $otherDestination->id,
            'destination_type' => $otherDestination->getMorphClass(),
        ]);

        $response = $this->withHeaders($this->headers)
            ->postJson("/api/v1/applications/{$otherApp->uuid}/clone", [
                'destination_uuid' => $this->destination->uuid,
            ]);

        $response->assertNotFound();
    });
});

describe('POST /api/v1/databases/{uuid}/clone', function () {
    test('clones a database and returns the new uuid', function () {
        $database = StandalonePostgresql::create([
            'name' => 'source-db',
            'image' => 'postgres:17-alpine',
            'postgres_user' => 'postgres',
            'postgres_password' => 'password',
            'postgres_db' => 'postgres',
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);

        $response = $this->withHeaders($this->headers)
            ->postJson("/api/v1/databases/{$database->uuid}/clone", [
                'destination_uuid' => $this->destination->uuid,
                'name' => 'cloned-db',
            ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Database cloned.');

        $cloned = StandalonePostgresql::where('uuid', $response->json('uuid'))->first();
        expect($cloned)->not->toBeNull()
            ->and($cloned->name)->toBe('cloned-db')
            ->and($cloned->environment_id)->toBe($database->environment_id)
            ->and($cloned->destination_id)->toBe($this->destination->id)
            ->and(str($cloned->status)->startsWith('exited'))->toBeTrue();
    });

    test('creates renamed volumes when cloning a database with clone_volumes', function () {
        // Prevent remote stop/clone/start from running in unit tests.
        Bus::fake();

        $database = StandalonePostgresql::create([
            'name' => 'source-db-volumes',
            'image' => 'postgres:17-alpine',
            'postgres_user' => 'postgres',
            'postgres_password' => 'password',
            'postgres_db' => 'postgres',
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);

        // Factory/create hooks may already create a data volume.
        if ($database->persistentStorages()->count() === 0) {
            LocalPersistentVolume::create([
                'name' => 'postgres-data-'.$database->uuid,
                'mount_path' => '/var/lib/postgresql/data',
                'resource_id' => $database->id,
                'resource_type' => $database->getMorphClass(),
            ]);
        }

        $sourceVolumeCount = $database->persistentStorages()->count();
        expect($sourceVolumeCount)->toBeGreaterThan(0);

        $response = $this->withHeaders($this->headers)
            ->postJson("/api/v1/databases/{$database->uuid}/clone", [
                'destination_uuid' => $this->destination->uuid,
                'name' => 'cloned-db-volumes',
                'clone_volumes' => true,
            ])
            ->assertCreated();

        $cloned = StandalonePostgresql::where('uuid', $response->json('uuid'))->firstOrFail();
        expect($cloned->persistentStorages()->count())->toBe($sourceVolumeCount)
            ->and($cloned->persistentStorages()->first()->name)->not->toBe($database->persistentStorages()->first()->name)
            ->and($cloned->persistentStorages()->first()->name)->toContain($cloned->uuid);
    });

    test('returns 404 for another team database', function () {
        $otherTeam = Team::factory()->create();
        $otherServer = Server::factory()->create(['team_id' => $otherTeam->id]);
        $otherDestination = StandaloneDocker::where('server_id', $otherServer->id)->firstOrFail();
        $otherProject = Project::factory()->create(['team_id' => $otherTeam->id]);
        $otherEnvironment = $otherProject->environments()->first()
            ?? Environment::factory()->create(['project_id' => $otherProject->id]);
        $otherDatabase = StandalonePostgresql::create([
            'name' => 'other-db',
            'image' => 'postgres:17-alpine',
            'postgres_user' => 'postgres',
            'postgres_password' => 'password',
            'postgres_db' => 'postgres',
            'environment_id' => $otherEnvironment->id,
            'destination_id' => $otherDestination->id,
            'destination_type' => $otherDestination->getMorphClass(),
        ]);

        $this->withHeaders($this->headers)
            ->postJson("/api/v1/databases/{$otherDatabase->uuid}/clone", [
                'destination_uuid' => $this->destination->uuid,
            ])
            ->assertNotFound();
    });
});

describe('POST /api/v1/services/{uuid}/clone', function () {
    test('clones a service and parses applications from compose', function () {
        $service = Service::factory()->create([
            'name' => 'source-service',
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
            'server_id' => $this->server->id,
            'docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n",
            'compose_parsing_version' => '5',
        ]);
        $service->parse();
        expect($service->applications()->count())->toBeGreaterThan(0);

        $response = $this->withHeaders($this->headers)
            ->postJson("/api/v1/services/{$service->uuid}/clone", [
                'destination_uuid' => $this->destination->uuid,
                'name' => 'cloned-service',
            ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Service cloned.');

        $cloned = Service::where('uuid', $response->json('uuid'))->first();
        expect($cloned)->not->toBeNull()
            ->and($cloned->name)->toBe('cloned-service')
            ->and($cloned->environment_id)->toBe($service->environment_id)
            ->and($cloned->applications()->count())->toBe($service->applications()->count());
    });

    test('returns 404 for another team service', function () {
        $otherTeam = Team::factory()->create();
        $otherServer = Server::factory()->create(['team_id' => $otherTeam->id]);
        $otherDestination = StandaloneDocker::where('server_id', $otherServer->id)->firstOrFail();
        $otherProject = Project::factory()->create(['team_id' => $otherTeam->id]);
        $otherEnvironment = $otherProject->environments()->first()
            ?? Environment::factory()->create(['project_id' => $otherProject->id]);
        $otherService = Service::factory()->create([
            'environment_id' => $otherEnvironment->id,
            'destination_id' => $otherDestination->id,
            'destination_type' => $otherDestination->getMorphClass(),
            'server_id' => $otherServer->id,
            'docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n",
        ]);

        $this->withHeaders($this->headers)
            ->postJson("/api/v1/services/{$otherService->uuid}/clone", [
                'destination_uuid' => $this->destination->uuid,
            ])
            ->assertNotFound();
    });
});

describe('DELETE resource endpoints', function () {
    test('soft deletes an application before queuing cleanup', function () {
        Queue::fake();

        $this->withHeaders($this->headers)
            ->deleteJson("/api/v1/applications/{$this->application->uuid}")
            ->assertOk();

        expect(Application::find($this->application->id))->toBeNull()
            ->and(Application::withTrashed()->find($this->application->id)?->trashed())->toBeTrue();
        Queue::assertPushed(DeleteResourceJob::class);
    });

    test('soft deletes a service before queuing cleanup', function () {
        Queue::fake();
        $service = Service::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
            'server_id' => $this->server->id,
        ]);

        $this->withHeaders($this->headers)
            ->deleteJson("/api/v1/services/{$service->uuid}")
            ->assertOk();

        expect(Service::find($service->id))->toBeNull()
            ->and(Service::withTrashed()->find($service->id)?->trashed())->toBeTrue();
        Queue::assertPushed(DeleteResourceJob::class);
    });

    test('soft deletes a database before queuing cleanup', function () {
        Queue::fake();
        $database = StandalonePostgresql::create([
            'name' => 'database-to-delete',
            'image' => 'postgres:17-alpine',
            'postgres_user' => 'postgres',
            'postgres_password' => 'password',
            'postgres_db' => 'postgres',
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);

        $this->withHeaders($this->headers)
            ->deleteJson("/api/v1/databases/{$database->uuid}")
            ->assertOk();

        expect(StandalonePostgresql::find($database->id))->toBeNull()
            ->and(StandalonePostgresql::withTrashed()->find($database->id)?->trashed())->toBeTrue();
        Queue::assertPushed(DeleteResourceJob::class);
    });
});

describe('POST /api/v1/applications/{uuid}/scheduled-tasks/{task_uuid}/execute', function () {
    test('dispatches ScheduledTaskJob for an application task', function () {
        Queue::fake();

        $task = ScheduledTask::factory()->create([
            'application_id' => $this->application->id,
            'team_id' => $this->team->id,
            'name' => 'nightly',
        ]);

        $response = $this->withHeaders($this->headers)
            ->postJson("/api/v1/applications/{$this->application->uuid}/scheduled-tasks/{$task->uuid}/execute");

        $response->assertOk()
            ->assertJsonPath('message', 'Scheduled task execution queued.');

        Queue::assertPushed(ScheduledTaskJob::class, fn (ScheduledTaskJob $job) => $job->task->is($task));
    });

    test('returns 404 for unknown task', function () {
        $response = $this->withHeaders($this->headers)
            ->postJson("/api/v1/applications/{$this->application->uuid}/scheduled-tasks/missing-task/execute");

        $response->assertNotFound();
    });
});

describe('PATCH /api/v1/databases/{uuid} health check fields', function () {
    test('can update database health_check fields', function () {
        $database = StandalonePostgresql::create([
            'name' => 'pg-health',
            'uuid' => (string) Str::uuid(),
            'postgres_password' => 'secret',
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);

        $response = $this->withHeaders($this->headers)
            ->patchJson("/api/v1/databases/{$database->uuid}", [
                'health_check_enabled' => false,
                'health_check_interval' => 30,
                'health_check_timeout' => 10,
                'health_check_retries' => 3,
                'health_check_start_period' => 20,
            ]);

        $response->assertOk();

        $database->refresh();
        expect($database->health_check_enabled)->toBeFalse()
            ->and($database->health_check_interval)->toBe(30)
            ->and($database->health_check_timeout)->toBe(10)
            ->and($database->health_check_retries)->toBe(3)
            ->and($database->health_check_start_period)->toBe(20);
    });
});

describe('Cloud-init scripts CRUD', function () {
    test('creates lists updates and deletes cloud-init scripts for the team', function () {
        $create = $this->withHeaders($this->headers)
            ->postJson('/api/v1/cloud-init-scripts', [
                'name' => 'bootstrap',
                'script' => "#!/bin/bash\necho hello",
            ]);

        $create->assertCreated()
            ->assertJsonPath('name', 'bootstrap')
            ->assertJsonStructure(['uuid', 'name']);

        $uuid = $create->json('uuid');

        $list = $this->withHeaders($this->headers)
            ->getJson('/api/v1/cloud-init-scripts');
        $list->assertOk();
        expect(collect($list->json())->pluck('uuid'))->toContain($uuid);

        $show = $this->withHeaders($this->headers)
            ->getJson("/api/v1/cloud-init-scripts/{$uuid}");
        $show->assertOk()->assertJsonPath('name', 'bootstrap');

        $update = $this->withHeaders($this->headers)
            ->patchJson("/api/v1/cloud-init-scripts/{$uuid}", [
                'name' => 'bootstrap-v2',
            ]);
        $update->assertOk()->assertJsonPath('name', 'bootstrap-v2');

        $delete = $this->withHeaders($this->headers)
            ->deleteJson("/api/v1/cloud-init-scripts/{$uuid}");
        $delete->assertOk();

        expect(CloudInitScript::where('uuid', $uuid)->exists())->toBeFalse();
    });

    test('returns 404 for another team cloud-init script', function () {
        $otherTeam = Team::factory()->create();
        $script = CloudInitScript::create([
            'team_id' => $otherTeam->id,
            'name' => 'other',
            'script' => "#!/bin/bash\necho other",
        ]);

        $this->withHeaders($this->headers)
            ->getJson("/api/v1/cloud-init-scripts/{$script->uuid}")
            ->assertNotFound();

        $this->withHeaders($this->headers)
            ->patchJson("/api/v1/cloud-init-scripts/{$script->uuid}", ['name' => 'nope'])
            ->assertNotFound();

        $this->withHeaders($this->headers)
            ->deleteJson("/api/v1/cloud-init-scripts/{$script->uuid}")
            ->assertNotFound();
    });
});

describe('Application multi-destination cross-team', function () {
    test('returns 404 when attaching another team destination', function () {
        $otherTeam = Team::factory()->create();
        $otherServer = Server::factory()->create(['team_id' => $otherTeam->id]);
        $otherDestination = StandaloneDocker::where('server_id', $otherServer->id)->firstOrFail();

        $response = $this->withHeaders($this->headers)
            ->postJson("/api/v1/applications/{$this->application->uuid}/destinations", [
                'destination_uuid' => $otherDestination->uuid,
            ]);

        $response->assertNotFound();
        expect($this->application->fresh()->additional_networks)->toHaveCount(0);
    });

    test('lists primary destination', function () {
        $response = $this->withHeaders($this->headers)
            ->getJson("/api/v1/applications/{$this->application->uuid}/destinations");

        $response->assertOk();
        expect($response->json())->toHaveCount(1)
            ->and($response->json('0.uuid'))->toBe($this->destination->uuid)
            ->and($response->json('0.is_primary'))->toBeTrue();
    });
});
