<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Server::flushIdentityMap();
    config()->set('app.maintenance.store', 'array');
    config()->set('app.maintenance.driver', 'file');
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);

    $this->team = Team::factory()->create();
    $user = User::factory()->create();
    $this->team->members()->attach($user->id, ['role' => 'owner']);

    $plainTextToken = Str::random(40);
    $token = $user->tokens()->create([
        'name' => 'ui-settings-api-parity-test',
        'token' => hash('sha256', $plainTextToken),
        'abilities' => ['*'],
        'team_id' => $this->team->id,
    ]);
    $this->headers = ['Authorization' => 'Bearer '.$token->getKey().'|'.$plainTextToken];

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);

    $this->database = StandalonePostgresql::create([
        'name' => 'db',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'app',
        'image' => 'postgres:16',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
});

function parityS3Storage(Team $team): S3Storage
{
    return S3Storage::create([
        'name' => 's3',
        'region' => 'us-east-1',
        'key' => 'key',
        'secret' => 'secret',
        'bucket' => 'bucket',
        'endpoint' => 'https://s3.example.com',
        'team_id' => $team->id,
        'is_usable' => true,
    ]);
}

function parityServiceApplication(object $ctx): ServiceApplication
{
    $service = Service::factory()->create([
        'environment_id' => $ctx->environment->id,
        'server_id' => $ctx->server->id,
        'destination_id' => $ctx->destination->id,
        'destination_type' => $ctx->destination->getMorphClass(),
        'docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n",
    ]);

    return ServiceApplication::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'web',
        'service_id' => $service->id,
        'image' => 'nginx:alpine',
    ]);
}

describe('applications', function () {
    test('creation accepts dockerfile_target_build', function () {
        $response = $this->withHeaders($this->headers)->postJson('/api/v1/applications/public', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'server_uuid' => $this->server->uuid,
            'git_repository' => 'https://gitlab.com/coolify/target-build-test',
            'git_branch' => 'main',
            'build_pack' => 'dockerfile',
            'ports_exposes' => '3000',
            'dockerfile_target_build' => 'production',
        ])->assertCreated();

        expect(Application::where('uuid', $response->json('uuid'))->firstOrFail()->dockerfile_target_build)->toBe('production');
    });

    test('PATCH updates include_coolify_default_variables', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);

        $this->withHeaders($this->headers)
            ->patchJson("/api/v1/applications/{$application->uuid}", ['include_coolify_default_variables' => false])
            ->assertOk();

        expect($application->fresh()->settings->include_coolify_default_variables)->toBeFalse();
    });
});

describe('databases', function () {
    test('PATCH saves is_log_drain_enabled when the server has a log drain', function () {
        $this->server->settings->update(['is_logdrain_newrelic_enabled' => true]);

        $this->withHeaders($this->headers)
            ->patchJson("/api/v1/databases/{$this->database->uuid}", ['is_log_drain_enabled' => true])
            ->assertOk();

        expect($this->database->fresh()->is_log_drain_enabled)->toBeTruthy();
    });

    test('PATCH rejects is_log_drain_enabled when the server has no log drain', function () {
        $this->withHeaders($this->headers)
            ->patchJson("/api/v1/databases/{$this->database->uuid}", ['is_log_drain_enabled' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_log_drain_enabled');

        expect($this->database->fresh()->is_log_drain_enabled)->toBeFalsy();
    });

    test('PATCH updates include_coolify_default_variables', function () {
        $this->withHeaders($this->headers)
            ->patchJson("/api/v1/databases/{$this->database->uuid}", ['include_coolify_default_variables' => false])
            ->assertOk();

        expect($this->database->fresh()->include_coolify_default_variables)->toBeFalse();
    });

    test('backup creation accepts disable_local_backup with S3', function () {
        $s3 = parityS3Storage($this->team);

        $response = $this->withHeaders($this->headers)->postJson("/api/v1/databases/{$this->database->uuid}/backups", [
            'frequency' => 'daily',
            'save_s3' => true,
            's3_storage_uuid' => $s3->uuid,
            'disable_local_backup' => true,
        ])->assertCreated();

        expect(ScheduledDatabaseBackup::where('uuid', $response->json('uuid'))->firstOrFail()->disable_local_backup)->toBeTruthy();
    });

    test('backup creation rejects disable_local_backup without S3', function () {
        $this->withHeaders($this->headers)->postJson("/api/v1/databases/{$this->database->uuid}/backups", [
            'frequency' => 'daily',
            'disable_local_backup' => true,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('disable_local_backup');

        expect(ScheduledDatabaseBackup::count())->toBe(0);
    });

    test('backup update turns local backups back on when S3 is turned off', function () {
        $s3 = parityS3Storage($this->team);
        $backup = ScheduledDatabaseBackup::create([
            'frequency' => 'daily',
            'save_s3' => true,
            's3_storage_id' => $s3->id,
            'disable_local_backup' => true,
            'database_id' => $this->database->id,
            'database_type' => $this->database->getMorphClass(),
            'team_id' => $this->team->id,
        ]);

        $this->withHeaders($this->headers)
            ->patchJson("/api/v1/databases/{$this->database->uuid}/backups/{$backup->uuid}", ['save_s3' => false])
            ->assertOk();

        expect($backup->fresh()->disable_local_backup)->toBeFalsy();

        $this->withHeaders($this->headers)
            ->patchJson("/api/v1/databases/{$this->database->uuid}/backups/{$backup->uuid}", ['disable_local_backup' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('disable_local_backup');
    });
});

describe('services', function () {
    test('PATCH updates include_coolify_default_variables', function () {
        $serviceApplication = parityServiceApplication($this);
        $service = $serviceApplication->service;

        $this->withHeaders($this->headers)
            ->patchJson("/api/v1/services/{$service->uuid}", ['include_coolify_default_variables' => false])
            ->assertOk();

        expect($service->fresh()->include_coolify_default_variables)->toBeFalse();
    });

    test('service application PATCH sets a www redirect when both hosts exist', function () {
        $serviceApplication = parityServiceApplication($this);

        $this->withHeaders($this->headers)
            ->patchJson("/api/v1/services/{$serviceApplication->service->uuid}/applications/{$serviceApplication->uuid}", [
                'url' => 'https://example.com,https://www.example.com',
                'redirect' => 'www',
            ])->assertOk();

        expect($serviceApplication->fresh()->redirect)->toBe('www');
    });

    test('service application PATCH rejects a www redirect without a www host', function () {
        $serviceApplication = parityServiceApplication($this);
        $serviceApplication->update(['fqdn' => 'https://example.com']);

        $this->withHeaders($this->headers)
            ->patchJson("/api/v1/services/{$serviceApplication->service->uuid}/applications/{$serviceApplication->uuid}", [
                'redirect' => 'www',
            ])->assertUnprocessable()
            ->assertJsonValidationErrors('redirect');

        expect($serviceApplication->fresh()->redirect)->not->toBe('www');
    });

    test('service application PATCH rejects an unknown redirect', function () {
        $serviceApplication = parityServiceApplication($this);

        $this->withHeaders($this->headers)
            ->patchJson("/api/v1/services/{$serviceApplication->service->uuid}/applications/{$serviceApplication->uuid}", [
                'redirect' => 'sometimes',
            ])->assertUnprocessable()
            ->assertJsonValidationErrors('redirect');
    });
});

describe('servers', function () {
    test('PATCH updates wildcard domain, timezone and backup compression', function () {
        $this->withHeaders($this->headers)->patchJson("/api/v1/servers/{$this->server->uuid}", [
            'wildcard_domain' => 'https://apps.example.com',
            'server_timezone' => 'Europe/Berlin',
            'backup_compression_cpu_percentage' => 50,
        ])->assertCreated();

        expect($this->server->settings->fresh())
            ->wildcard_domain->toBe('https://apps.example.com')
            ->server_timezone->toBe('Europe/Berlin')
            ->backup_compression_cpu_percentage->toBe(50);

        $this->withHeaders($this->headers)
            ->patchJson("/api/v1/servers/{$this->server->uuid}", ['wildcard_domain' => null])
            ->assertCreated();

        expect($this->server->settings->fresh()->wildcard_domain)->toBeNull();
    });

    test('PATCH rejects invalid server settings', function (string $field, mixed $value) {
        $this->withHeaders($this->headers)
            ->patchJson("/api/v1/servers/{$this->server->uuid}", [$field => $value])
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    })->with([
        'wildcard domain' => ['wildcard_domain', 'not a url'],
        'timezone' => ['server_timezone', 'Mars/Olympus'],
        'compression' => ['backup_compression_cpu_percentage', 30],
    ]);

    test('proxy PATCH sets and clears the custom error page', function () {
        $this->withHeaders($this->headers)
            ->patchJson("/api/v1/servers/{$this->server->uuid}/proxy", ['proxy_error_page' => '<h1>Not here</h1>'])
            ->assertOk()
            ->assertJsonPath('proxy_error_page', '<h1>Not here</h1>');

        expect($this->server->fresh()->proxy_error_page)->toBe('<h1>Not here</h1>');

        $this->withHeaders($this->headers)
            ->patchJson("/api/v1/servers/{$this->server->uuid}/proxy", ['proxy_error_page' => null])
            ->assertOk();

        expect($this->server->fresh()->proxy_error_page)->toBeNull();
    });
});
