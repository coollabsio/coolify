<?php

use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function makeApiToken(User $user, Team $team, array $abilities): string
{
    session(['currentTeam' => $team]);
    $token = $user->createToken('sensitive-test', $abilities);
    DB::table('personal_access_tokens')->where('id', $token->accessToken->id)->update([
        'team_id' => $team->id,
    ]);

    return $token->plainTextToken;
}

function makeTeamUser(): array
{
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);
    session(['currentTeam' => $team]);

    return [$team, $user];
}

beforeEach(function () {
    InstanceSettings::query()->delete();
    $settings = new InstanceSettings;
    $settings->id = 0;
    $settings->save();

    [$this->team, $this->user] = makeTeamUser();

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->server->settings->forceFill([
        'sentinel_token' => encrypt('super-secret-sentinel-token'),
        'sentinel_custom_url' => 'https://sentinel.internal',
        'logdrain_axiom_api_key' => encrypt('axiom-key-secret'),
        'logdrain_newrelic_license_key' => encrypt('newrelic-key-secret'),
        'logdrain_custom_config' => 'custom-config-data',
        'logdrain_custom_config_parser' => 'custom-parser-data',
    ])->saveQuietly();
});

describe('GET /api/v1/servers sensitive field gating', function () {
    test('read token does not leak sentinel or logdrain fields', function () {
        $token = makeApiToken($this->user, $this->team, ['read']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v1/servers');

        $response->assertStatus(200);

        $body = $response->getContent();
        expect($body)->not->toContain('sentinel_token');
        expect($body)->not->toContain('sentinel_custom_url');
        expect($body)->not->toContain('logdrain_axiom_api_key');
        expect($body)->not->toContain('logdrain_newrelic_license_key');
        expect($body)->not->toContain('logdrain_custom_config');
    });

    test('read sensitive token sees sentinel and logdrain fields', function () {
        $token = makeApiToken($this->user, $this->team, ['read', 'read:sensitive']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v1/servers');

        $response->assertStatus(200);

        $body = $response->getContent();
        expect($body)->toContain('sentinel_token');
        expect($body)->toContain('sentinel_custom_url');
        expect($body)->toContain('logdrain_axiom_api_key');
    });

    test('root token sees sentinel and logdrain fields', function () {
        $token = makeApiToken($this->user, $this->team, ['root']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v1/servers');

        $response->assertStatus(200);

        $body = $response->getContent();
        expect($body)->toContain('sentinel_token');
    });

    test('read token does not leak sentinel or logdrain fields in server detail', function () {
        $token = makeApiToken($this->user, $this->team, ['read']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson("/api/v1/servers/{$this->server->uuid}");

        $response->assertStatus(200);

        $body = $response->getContent();
        expect($body)->not->toContain('sentinel_token');
        expect($body)->not->toContain('sentinel_custom_url');
        expect($body)->not->toContain('logdrain_axiom_api_key');
        expect($body)->not->toContain('logdrain_newrelic_license_key');
        expect($body)->not->toContain('logdrain_custom_config');
    });

    test('read sensitive token sees sentinel and logdrain fields in server detail', function () {
        $token = makeApiToken($this->user, $this->team, ['read', 'read:sensitive']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson("/api/v1/servers/{$this->server->uuid}");

        $response->assertStatus(200);

        $body = $response->getContent();
        expect($body)->toContain('sentinel_token');
        expect($body)->toContain('sentinel_custom_url');
        expect($body)->toContain('logdrain_axiom_api_key');
    });

    test('server resources response does not leak server secrets', function () {
        $token = makeApiToken($this->user, $this->team, ['read', 'read:sensitive']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson("/api/v1/servers/{$this->server->uuid}/resources");

        $response->assertStatus(200);

        $body = $response->getContent();
        expect($body)->not->toContain('sentinel_token');
        expect($body)->not->toContain('logdrain_axiom_api_key');
        expect($body)->not->toContain('logdrain_newrelic_license_key');
    });
});

describe('GET /api/v1/security/keys sensitive field gating', function () {
    beforeEach(function () {
        PrivateKey::withoutEvents(function () {
            PrivateKey::forceCreate([
                'uuid' => 'private-key-sensitive-test',
                'name' => 'Sensitive key',
                'description' => 'test',
                'private_key' => 'super-secret-private-key',
                'is_git_related' => false,
                'team_id' => $this->team->id,
            ]);
        });
    });

    test('read token does not leak private key material', function () {
        $token = makeApiToken($this->user, $this->team, ['read']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v1/security/keys/private-key-sensitive-test');

        $response->assertStatus(200);

        expect($response->getContent())->not->toContain('"private_key":');
    });

    test('read sensitive token sees private key material', function () {
        $token = makeApiToken($this->user, $this->team, ['read', 'read:sensitive']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v1/security/keys/private-key-sensitive-test');

        $response->assertStatus(200);

        expect($response->getContent())->toContain('"private_key":');
    });

    test('read token does not leak private key material in key list', function () {
        $token = makeApiToken($this->user, $this->team, ['read']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v1/security/keys');

        $response->assertStatus(200);

        expect($response->getContent())->not->toContain('"private_key":');
    });

    test('read sensitive token sees private key material in key list', function () {
        $token = makeApiToken($this->user, $this->team, ['read', 'read:sensitive']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v1/security/keys');

        $response->assertStatus(200);

        expect($response->getContent())->toContain('"private_key":');
    });
});

describe('GET /api/v1/deployments sensitive field gating', function () {
    beforeEach(function () {
        $this->project = Project::factory()->create(['team_id' => $this->team->id]);
        $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
        $destination = $this->server->standaloneDockers()->firstOrFail();

        $this->application = Application::create([
            'name' => 'deployment-sensitive-test-app',
            'git_repository' => 'https://github.com/test/test',
            'git_branch' => 'main',
            'build_pack' => 'nixpacks',
            'ports_exposes' => '3000',
            'environment_id' => $this->environment->id,
            'destination_id' => $destination->id,
            'destination_type' => $destination->getMorphClass(),
        ]);

        ApplicationDeploymentQueue::create([
            'application_id' => $this->application->id,
            'deployment_uuid' => 'deployment-sensitive-test',
            'pull_request_id' => 0,
            'status' => 'in_progress',
            'logs' => '[{"output":"super-secret-deployment-log"}]',
            'server_id' => $this->server->id,
            'application_name' => $this->application->name,
            'server_name' => $this->server->name,
        ]);
    });

    test('read token does not leak deployment logs', function () {
        $token = makeApiToken($this->user, $this->team, ['read']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v1/deployments/deployment-sensitive-test');

        $response->assertStatus(200);

        expect($response->getContent())->not->toContain('"logs":');
    });

    test('read sensitive token sees deployment logs', function () {
        $token = makeApiToken($this->user, $this->team, ['read', 'read:sensitive']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v1/deployments/deployment-sensitive-test');

        $response->assertStatus(200);

        expect($response->getContent())->toContain('"logs":');
    });

    test('read token does not leak deployment logs in deployment list', function () {
        $token = makeApiToken($this->user, $this->team, ['read']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v1/deployments');

        $response->assertStatus(200);

        expect($response->getContent())->not->toContain('"logs":');
    });

    test('read sensitive token sees deployment logs in deployment list', function () {
        $token = makeApiToken($this->user, $this->team, ['read', 'read:sensitive']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v1/deployments');

        $response->assertStatus(200);

        expect($response->getContent())->toContain('"logs":');
    });

    test('read token does not leak deployment logs in application deployment history', function () {
        $token = makeApiToken($this->user, $this->team, ['read']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson("/api/v1/deployments/applications/{$this->application->uuid}");

        $response->assertStatus(200);

        expect($response->getContent())->not->toContain('"logs":');
    });

    test('read sensitive token sees deployment logs in application deployment history', function () {
        $token = makeApiToken($this->user, $this->team, ['read', 'read:sensitive']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson("/api/v1/deployments/applications/{$this->application->uuid}");

        $response->assertStatus(200);

        expect($response->getContent())->toContain('"logs":');
    });
});

describe('GET /api/v1/applications nested-relation scrubbing', function () {
    beforeEach(function () {
        $this->project = Project::factory()->create(['team_id' => $this->team->id]);
        $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
        $destination = $this->server->standaloneDockers()->firstOrFail();

        $this->application = Application::create([
            'name' => 'sensitive-test-app',
            'git_repository' => 'https://github.com/test/test',
            'git_branch' => 'main',
            'build_pack' => 'nixpacks',
            'ports_exposes' => '3000',
            'environment_id' => $this->environment->id,
            'destination_id' => $destination->id,
            'destination_type' => $destination->getMorphClass(),
        ]);
    });

    test('read token does not leak sentinel_token via destination.server.settings', function () {
        $token = makeApiToken($this->user, $this->team, ['read']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v1/applications');

        $response->assertStatus(200);

        $body = $response->getContent();
        expect($body)->not->toContain('"sentinel_token":');
        expect($body)->not->toContain('"sentinel_custom_url":');
        expect($body)->not->toContain('"logdrain_axiom_api_key":');
    });

    test('read-sensitive token sees nested sentinel_token via destination.server.settings', function () {
        $token = makeApiToken($this->user, $this->team, ['read', 'read:sensitive']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v1/applications');

        $response->assertStatus(200);

        $body = $response->getContent();
        expect($body)->toContain('"sentinel_token":');
        expect($body)->toContain('"sentinel_custom_url":');
    });

    test('read token does not leak application detail sensitive fields', function () {
        $this->application->forceFill([
            'manual_webhook_secret_github' => 'super-secret-github-webhook',
            'http_basic_auth_password' => 'super-secret-basic-password',
        ])->save();

        $token = makeApiToken($this->user, $this->team, ['read']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson("/api/v1/applications/{$this->application->uuid}");

        $response->assertStatus(200);

        $body = $response->getContent();
        expect($body)->not->toContain('"manual_webhook_secret_github":')
            ->and($body)->not->toContain('"http_basic_auth_password":')
            ->and($body)->not->toContain('"sentinel_token":');
    });

    test('read sensitive token sees application detail sensitive fields', function () {
        $this->application->forceFill([
            'manual_webhook_secret_github' => 'super-secret-github-webhook',
            'http_basic_auth_password' => 'super-secret-basic-password',
        ])->save();

        $token = makeApiToken($this->user, $this->team, ['read', 'read:sensitive']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson("/api/v1/applications/{$this->application->uuid}");

        $response->assertStatus(200);

        $body = $response->getContent();
        expect($body)->toContain('"manual_webhook_secret_github":')
            ->and($body)->toContain('"http_basic_auth_password":')
            ->and($body)->toContain('"sentinel_token":');
    });

    test('application env responses hide values for read tokens and reveal them for sensitive tokens', function () {
        $this->application->environment_variables()->create([
            'key' => 'APP_SECRET',
            'value' => 'super-secret-app-env',
        ]);

        $readToken = makeApiToken($this->user, $this->team, ['read']);
        $sensitiveToken = makeApiToken($this->user, $this->team, ['read', 'read:sensitive']);

        $readResponse = $this->withHeaders([
            'Authorization' => 'Bearer '.$readToken,
        ])->getJson("/api/v1/applications/{$this->application->uuid}/envs");

        auth()->forgetGuards();

        $sensitiveResponse = $this->withHeaders([
            'Authorization' => 'Bearer '.$sensitiveToken,
        ])->getJson("/api/v1/applications/{$this->application->uuid}/envs");

        $readResponse->assertStatus(200);
        $sensitiveResponse->assertStatus(200);

        expect($readResponse->getContent())->not->toContain('"value":')
            ->and($readResponse->getContent())->not->toContain('"real_value":')
            ->and($sensitiveResponse->getContent())->toContain('"value":')
            ->and($sensitiveResponse->getContent())->toContain('"real_value":');
    });

    test('application create env response does not include secret values', function () {
        $writeToken = makeApiToken($this->user, $this->team, ['write']);
        $sensitiveToken = makeApiToken($this->user, $this->team, ['write', 'read:sensitive']);

        $writeResponse = $this->withHeaders([
            'Authorization' => 'Bearer '.$writeToken,
        ])->postJson("/api/v1/applications/{$this->application->uuid}/envs", [
            'key' => 'APP_CREATE_SECRET',
            'value' => 'super-secret-app-create-env',
        ]);

        auth()->forgetGuards();

        $sensitiveResponse = $this->withHeaders([
            'Authorization' => 'Bearer '.$sensitiveToken,
        ])->postJson("/api/v1/applications/{$this->application->uuid}/envs", [
            'key' => 'APP_CREATE_SENSITIVE_SECRET',
            'value' => 'super-secret-app-create-sensitive-env',
        ]);

        $writeResponse->assertStatus(201);
        $sensitiveResponse->assertStatus(201);

        expect($writeResponse->getContent())->not->toContain('"value":')
            ->and($writeResponse->getContent())->not->toContain('"real_value":')
            ->and($sensitiveResponse->getContent())->not->toContain('"value":')
            ->and($sensitiveResponse->getContent())->not->toContain('"real_value":');
    });

    test('application update env response hides values for write tokens and reveals them for sensitive tokens', function () {
        $this->application->environment_variables()->create([
            'key' => 'APP_UPDATE_SECRET',
            'value' => 'old-app-update-secret',
        ]);

        $writeToken = makeApiToken($this->user, $this->team, ['write']);
        $sensitiveToken = makeApiToken($this->user, $this->team, ['write', 'read:sensitive']);

        $writeResponse = $this->withHeaders([
            'Authorization' => 'Bearer '.$writeToken,
        ])->patchJson("/api/v1/applications/{$this->application->uuid}/envs", [
            'key' => 'APP_UPDATE_SECRET',
            'value' => 'hidden-app-update-secret',
            'is_multiline' => false,
            'is_shown_once' => false,
        ]);

        auth()->forgetGuards();

        $sensitiveResponse = $this->withHeaders([
            'Authorization' => 'Bearer '.$sensitiveToken,
        ])->patchJson("/api/v1/applications/{$this->application->uuid}/envs", [
            'key' => 'APP_UPDATE_SECRET',
            'value' => 'visible-app-update-secret',
            'is_multiline' => false,
            'is_shown_once' => false,
        ]);

        $writeResponse->assertStatus(201);
        $sensitiveResponse->assertStatus(201);

        expect($writeResponse->getContent())->not->toContain('"value":')
            ->and($writeResponse->getContent())->not->toContain('"real_value":')
            ->and($sensitiveResponse->getContent())->toContain('"value":')
            ->and($sensitiveResponse->getContent())->toContain('"real_value":');
    });

    test('application bulk env response hides values for write tokens and reveals them for sensitive tokens', function () {
        $writeToken = makeApiToken($this->user, $this->team, ['write']);
        $sensitiveToken = makeApiToken($this->user, $this->team, ['write', 'read:sensitive']);

        $writeResponse = $this->withHeaders([
            'Authorization' => 'Bearer '.$writeToken,
        ])->patchJson("/api/v1/applications/{$this->application->uuid}/envs/bulk", [
            'data' => [[
                'key' => 'APP_BULK_SECRET',
                'value' => 'hidden-app-bulk-secret',
            ]],
        ]);

        auth()->forgetGuards();

        $sensitiveResponse = $this->withHeaders([
            'Authorization' => 'Bearer '.$sensitiveToken,
        ])->patchJson("/api/v1/applications/{$this->application->uuid}/envs/bulk", [
            'data' => [[
                'key' => 'APP_BULK_SENSITIVE_SECRET',
                'value' => 'visible-app-bulk-secret',
            ]],
        ]);

        $writeResponse->assertStatus(201);
        $sensitiveResponse->assertStatus(201);

        expect($writeResponse->getContent())->not->toContain('"value":')
            ->and($writeResponse->getContent())->not->toContain('"real_value":')
            ->and($sensitiveResponse->getContent())->toContain('"value":')
            ->and($sensitiveResponse->getContent())->toContain('"real_value":');
    });
});

describe('GET /api/v1/databases sensitive field gating', function () {
    beforeEach(function () {
        $this->project = Project::factory()->create(['team_id' => $this->team->id]);
        $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
        $destination = $this->server->standaloneDockers()->firstOrFail();

        $this->database = StandalonePostgresql::create([
            'name' => 'sensitive-db',
            'description' => 'test',
            'postgres_user' => 'postgres',
            'postgres_password' => encrypt('super-secret-db-password'),
            'postgres_db' => 'app',
            'image' => 'postgres:16-alpine',
            'environment_id' => $this->environment->id,
            'destination_id' => $destination->id,
            'destination_type' => $destination->getMorphClass(),
        ]);
    });

    test('read token database list does not lazy load nested server relations', function () {
        $token = makeApiToken($this->user, $this->team, ['read']);

        $preventedLazyLoading = Model::preventsLazyLoading();
        Model::preventLazyLoading();

        try {
            $response = $this->withoutExceptionHandling()->withHeaders([
                'Authorization' => 'Bearer '.$token,
            ])->getJson('/api/v1/databases');
        } finally {
            Model::preventLazyLoading($preventedLazyLoading);
        }

        $response->assertStatus(200);
    });

    test('read token does not leak postgres_password or db urls', function () {
        $token = makeApiToken($this->user, $this->team, ['read']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v1/databases');

        $response->assertStatus(200);

        $body = $response->getContent();
        expect($body)->not->toContain('postgres_password');
        expect($body)->not->toContain('internal_db_url');
        expect($body)->not->toContain('external_db_url');
    });

    test('read sensitive token sees postgres_password and nested sentinel_token', function () {
        $token = makeApiToken($this->user, $this->team, ['read', 'read:sensitive']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v1/databases');

        $response->assertStatus(200);

        $body = $response->getContent();
        expect($body)->toContain('"postgres_password":');
        expect($body)->toContain('"internal_db_url":');
        expect($body)->toContain('"sentinel_token":');
    });

    test('read token does not leak database detail sensitive fields', function () {
        $token = makeApiToken($this->user, $this->team, ['read']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson("/api/v1/databases/{$this->database->uuid}");

        $response->assertStatus(200);

        $body = $response->getContent();
        expect($body)->not->toContain('"postgres_password":')
            ->and($body)->not->toContain('"internal_db_url":')
            ->and($body)->not->toContain('"external_db_url":')
            ->and($body)->not->toContain('"sentinel_token":');
    });

    test('read sensitive token sees database detail sensitive fields', function () {
        $token = makeApiToken($this->user, $this->team, ['read', 'read:sensitive']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson("/api/v1/databases/{$this->database->uuid}");

        $response->assertStatus(200);

        $body = $response->getContent();
        expect($body)->toContain('"postgres_password":')
            ->and($body)->toContain('"internal_db_url":')
            ->and($body)->toContain('"sentinel_token":');
    });

    test('database env responses hide values for read tokens and reveal them for sensitive tokens', function () {
        $this->database->environment_variables()->create([
            'key' => 'DB_SECRET',
            'value' => 'super-secret-db-env',
        ]);

        $readToken = makeApiToken($this->user, $this->team, ['read']);
        $sensitiveToken = makeApiToken($this->user, $this->team, ['read', 'read:sensitive']);

        $readResponse = $this->withHeaders([
            'Authorization' => 'Bearer '.$readToken,
        ])->getJson("/api/v1/databases/{$this->database->uuid}/envs");

        auth()->forgetGuards();

        $sensitiveResponse = $this->withHeaders([
            'Authorization' => 'Bearer '.$sensitiveToken,
        ])->getJson("/api/v1/databases/{$this->database->uuid}/envs");

        $readResponse->assertStatus(200);
        $sensitiveResponse->assertStatus(200);

        expect($readResponse->getContent())->not->toContain('"value":')
            ->and($readResponse->getContent())->not->toContain('"real_value":')
            ->and($sensitiveResponse->getContent())->toContain('"value":')
            ->and($sensitiveResponse->getContent())->toContain('"real_value":');
    });

    test('database create env response hides values for write tokens and reveals them for sensitive tokens', function () {
        $writeToken = makeApiToken($this->user, $this->team, ['write']);
        $sensitiveToken = makeApiToken($this->user, $this->team, ['write', 'read:sensitive']);

        $writeResponse = $this->withHeaders([
            'Authorization' => 'Bearer '.$writeToken,
        ])->postJson("/api/v1/databases/{$this->database->uuid}/envs", [
            'key' => 'DB_CREATE_SECRET',
            'value' => 'hidden-db-create-secret',
        ]);

        auth()->forgetGuards();

        $sensitiveResponse = $this->withHeaders([
            'Authorization' => 'Bearer '.$sensitiveToken,
        ])->postJson("/api/v1/databases/{$this->database->uuid}/envs", [
            'key' => 'DB_CREATE_SENSITIVE_SECRET',
            'value' => 'visible-db-create-secret',
        ]);

        $writeResponse->assertStatus(201);
        $sensitiveResponse->assertStatus(201);

        expect($writeResponse->getContent())->not->toContain('"value":')
            ->and($writeResponse->getContent())->not->toContain('"real_value":')
            ->and($sensitiveResponse->getContent())->toContain('"value":')
            ->and($sensitiveResponse->getContent())->toContain('"real_value":');
    });

    test('database update env response hides values for write tokens and reveals them for sensitive tokens', function () {
        $this->database->environment_variables()->create([
            'key' => 'DB_UPDATE_SECRET',
            'value' => 'old-db-update-secret',
        ]);

        $writeToken = makeApiToken($this->user, $this->team, ['write']);
        $sensitiveToken = makeApiToken($this->user, $this->team, ['write', 'read:sensitive']);

        $writeResponse = $this->withHeaders([
            'Authorization' => 'Bearer '.$writeToken,
        ])->patchJson("/api/v1/databases/{$this->database->uuid}/envs", [
            'key' => 'DB_UPDATE_SECRET',
            'value' => 'hidden-db-update-secret',
        ]);

        auth()->forgetGuards();

        $sensitiveResponse = $this->withHeaders([
            'Authorization' => 'Bearer '.$sensitiveToken,
        ])->patchJson("/api/v1/databases/{$this->database->uuid}/envs", [
            'key' => 'DB_UPDATE_SECRET',
            'value' => 'visible-db-update-secret',
        ]);

        $writeResponse->assertStatus(201);
        $sensitiveResponse->assertStatus(201);

        expect($writeResponse->getContent())->not->toContain('"value":')
            ->and($writeResponse->getContent())->not->toContain('"real_value":')
            ->and($sensitiveResponse->getContent())->toContain('"value":')
            ->and($sensitiveResponse->getContent())->toContain('"real_value":');
    });

    test('database bulk env response hides values for write tokens and reveals them for sensitive tokens', function () {
        $writeToken = makeApiToken($this->user, $this->team, ['write']);
        $sensitiveToken = makeApiToken($this->user, $this->team, ['write', 'read:sensitive']);

        $writeResponse = $this->withHeaders([
            'Authorization' => 'Bearer '.$writeToken,
        ])->patchJson("/api/v1/databases/{$this->database->uuid}/envs/bulk", [
            'data' => [[
                'key' => 'DB_BULK_SECRET',
                'value' => 'hidden-db-bulk-secret',
            ]],
        ]);

        auth()->forgetGuards();

        $sensitiveResponse = $this->withHeaders([
            'Authorization' => 'Bearer '.$sensitiveToken,
        ])->patchJson("/api/v1/databases/{$this->database->uuid}/envs/bulk", [
            'data' => [[
                'key' => 'DB_BULK_SENSITIVE_SECRET',
                'value' => 'visible-db-bulk-secret',
            ]],
        ]);

        $writeResponse->assertStatus(201);
        $sensitiveResponse->assertStatus(201);

        expect($writeResponse->getContent())->not->toContain('"value":')
            ->and($writeResponse->getContent())->not->toContain('"real_value":')
            ->and($sensitiveResponse->getContent())->toContain('"value":')
            ->and($sensitiveResponse->getContent())->toContain('"real_value":');
    });

    test('project database list can eager load nested destination server settings', function () {
        $databases = $this->project->databases(['destination.server.settings']);
        $database = $databases->firstWhere('id', $this->database->id);

        expect($database)->not->toBeNull()
            ->and($database->relationLoaded('destination'))->toBeTrue()
            ->and($database->destination->relationLoaded('server'))->toBeTrue()
            ->and($database->destination->server->relationLoaded('settings'))->toBeTrue();
    });
});

describe('GET /api/v1/services sensitive field gating', function () {
    beforeEach(function () {
        $this->project = Project::factory()->create(['team_id' => $this->team->id]);
        $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);

        $destination = $this->server->standaloneDockers()->firstOrFail();
        $this->service = Service::factory()->create([
            'server_id' => $this->server->id,
            'destination_id' => $destination->id,
            'destination_type' => $destination->getMorphClass(),
            'environment_id' => $this->environment->id,
        ]);
    });

    test('read token does not leak service or nested server sensitive fields', function () {
        $token = makeApiToken($this->user, $this->team, ['read']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v1/services');

        $response->assertStatus(200);

        $body = $response->getContent();
        expect($body)->not->toContain('"docker_compose_raw":')
            ->and($body)->not->toContain('"sentinel_token":')
            ->and($body)->not->toContain('"sentinel_custom_url":');
    });

    test('read sensitive token sees service and nested server sensitive fields', function () {
        $token = makeApiToken($this->user, $this->team, ['read', 'read:sensitive']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v1/services');

        $response->assertStatus(200);

        $body = $response->getContent();
        expect($body)->toContain('"docker_compose_raw":')
            ->and($body)->toContain('"sentinel_token":')
            ->and($body)->toContain('"sentinel_custom_url":');
    });

    test('read token does not leak service detail sensitive fields', function () {
        $this->service->forceFill([
            'docker_compose_raw' => 'services: secret',
            'docker_compose' => 'services: rendered',
        ])->save();

        $token = makeApiToken($this->user, $this->team, ['read']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson("/api/v1/services/{$this->service->uuid}");

        $response->assertStatus(200);

        $body = $response->getContent();
        expect($body)->not->toContain('"docker_compose_raw":')
            ->and($body)->not->toContain('"docker_compose":')
            ->and($body)->not->toContain('"sentinel_token":');
    });

    test('read sensitive token sees service detail sensitive fields', function () {
        $this->service->forceFill([
            'docker_compose_raw' => 'services: secret',
            'docker_compose' => 'services: rendered',
        ])->save();

        $token = makeApiToken($this->user, $this->team, ['read', 'read:sensitive']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson("/api/v1/services/{$this->service->uuid}");

        $response->assertStatus(200);

        $body = $response->getContent();
        expect($body)->toContain('"docker_compose_raw":')
            ->and($body)->toContain('"docker_compose":')
            ->and($body)->toContain('"sentinel_token":');
    });

    test('service env responses hide values for read tokens and reveal them for sensitive tokens', function () {
        $this->service->environment_variables()->create([
            'key' => 'SERVICE_SECRET',
            'value' => 'super-secret-service-env',
        ]);

        $readToken = makeApiToken($this->user, $this->team, ['read']);
        $sensitiveToken = makeApiToken($this->user, $this->team, ['read', 'read:sensitive']);

        $readResponse = $this->withHeaders([
            'Authorization' => 'Bearer '.$readToken,
        ])->getJson("/api/v1/services/{$this->service->uuid}/envs");

        auth()->forgetGuards();

        $sensitiveResponse = $this->withHeaders([
            'Authorization' => 'Bearer '.$sensitiveToken,
        ])->getJson("/api/v1/services/{$this->service->uuid}/envs");

        $readResponse->assertStatus(200);
        $sensitiveResponse->assertStatus(200);

        expect($readResponse->getContent())->not->toContain('"value":')
            ->and($readResponse->getContent())->not->toContain('"real_value":')
            ->and($sensitiveResponse->getContent())->toContain('"value":')
            ->and($sensitiveResponse->getContent())->toContain('"real_value":');
    });

    test('service create env response hides values for write tokens and reveals them for sensitive tokens', function () {
        $writeToken = makeApiToken($this->user, $this->team, ['write']);
        $sensitiveToken = makeApiToken($this->user, $this->team, ['write', 'read:sensitive']);

        $writeResponse = $this->withHeaders([
            'Authorization' => 'Bearer '.$writeToken,
        ])->postJson("/api/v1/services/{$this->service->uuid}/envs", [
            'key' => 'SERVICE_CREATE_SECRET',
            'value' => 'hidden-service-create-secret',
        ]);

        auth()->forgetGuards();

        $sensitiveResponse = $this->withHeaders([
            'Authorization' => 'Bearer '.$sensitiveToken,
        ])->postJson("/api/v1/services/{$this->service->uuid}/envs", [
            'key' => 'SERVICE_CREATE_SENSITIVE_SECRET',
            'value' => 'visible-service-create-secret',
        ]);

        $writeResponse->assertStatus(201);
        $sensitiveResponse->assertStatus(201);

        expect($writeResponse->getContent())->not->toContain('"value":')
            ->and($writeResponse->getContent())->not->toContain('"real_value":')
            ->and($sensitiveResponse->getContent())->toContain('"value":')
            ->and($sensitiveResponse->getContent())->toContain('"real_value":');
    });

    test('service update env response hides values for write tokens and reveals them for sensitive tokens', function () {
        $this->service->environment_variables()->create([
            'key' => 'SERVICE_UPDATE_SECRET',
            'value' => 'old-service-update-secret',
        ]);

        $writeToken = makeApiToken($this->user, $this->team, ['write']);
        $sensitiveToken = makeApiToken($this->user, $this->team, ['write', 'read:sensitive']);

        $writeResponse = $this->withHeaders([
            'Authorization' => 'Bearer '.$writeToken,
        ])->patchJson("/api/v1/services/{$this->service->uuid}/envs", [
            'key' => 'SERVICE_UPDATE_SECRET',
            'value' => 'hidden-service-update-secret',
        ]);

        auth()->forgetGuards();

        $sensitiveResponse = $this->withHeaders([
            'Authorization' => 'Bearer '.$sensitiveToken,
        ])->patchJson("/api/v1/services/{$this->service->uuid}/envs", [
            'key' => 'SERVICE_UPDATE_SECRET',
            'value' => 'visible-service-update-secret',
        ]);

        $writeResponse->assertStatus(201);
        $sensitiveResponse->assertStatus(201);

        expect($writeResponse->getContent())->not->toContain('"value":')
            ->and($writeResponse->getContent())->not->toContain('"real_value":')
            ->and($sensitiveResponse->getContent())->toContain('"value":')
            ->and($sensitiveResponse->getContent())->toContain('"real_value":');
    });

    test('service bulk env response hides values for write tokens and reveals them for sensitive tokens', function () {
        $writeToken = makeApiToken($this->user, $this->team, ['write']);
        $sensitiveToken = makeApiToken($this->user, $this->team, ['write', 'read:sensitive']);

        $writeResponse = $this->withHeaders([
            'Authorization' => 'Bearer '.$writeToken,
        ])->patchJson("/api/v1/services/{$this->service->uuid}/envs/bulk", [
            'data' => [[
                'key' => 'SERVICE_BULK_SECRET',
                'value' => 'hidden-service-bulk-secret',
            ]],
        ]);

        auth()->forgetGuards();

        $sensitiveResponse = $this->withHeaders([
            'Authorization' => 'Bearer '.$sensitiveToken,
        ])->patchJson("/api/v1/services/{$this->service->uuid}/envs/bulk", [
            'data' => [[
                'key' => 'SERVICE_BULK_SENSITIVE_SECRET',
                'value' => 'visible-service-bulk-secret',
            ]],
        ]);

        $writeResponse->assertStatus(201);
        $sensitiveResponse->assertStatus(201);

        expect($writeResponse->getContent())->not->toContain('"value":')
            ->and($writeResponse->getContent())->not->toContain('"real_value":')
            ->and($sensitiveResponse->getContent())->toContain('"value":')
            ->and($sensitiveResponse->getContent())->toContain('"real_value":');
    });

    test('read sensitive service list eager loads nested server settings once', function () {
        $secondServer = Server::factory()->create(['team_id' => $this->team->id]);
        $secondDestination = $secondServer->standaloneDockers()->firstOrFail();

        Service::factory()->create([
            'server_id' => $secondServer->id,
            'destination_id' => $secondDestination->id,
            'destination_type' => $secondDestination->getMorphClass(),
            'environment_id' => $this->environment->id,
        ]);

        $token = makeApiToken($this->user, $this->team, ['read', 'read:sensitive']);
        $serverSettingsQueries = collect();

        DB::listen(function ($query) use ($serverSettingsQueries) {
            if (str_contains($query->sql, 'from "server_settings"')) {
                $serverSettingsQueries->push($query->sql);
            }
        });

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v1/services');

        $response->assertStatus(200);

        expect($serverSettingsQueries->contains(fn (string $sql) => str_contains($sql, '"server_settings"."server_id" in')))->toBeTrue();
    });
});

describe('GET /api/v1/resources sensitive field gating', function () {
    beforeEach(function () {
        $this->project = Project::factory()->create(['team_id' => $this->team->id]);
        $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
        $destination = $this->server->standaloneDockers()->firstOrFail();

        $this->database = StandalonePostgresql::create([
            'name' => 'resources-sensitive-db',
            'postgres_user' => 'postgres',
            'postgres_password' => encrypt('super-secret-db-password'),
            'postgres_db' => 'app',
            'image' => 'postgres:16-alpine',
            'environment_id' => $this->environment->id,
            'destination_id' => $destination->id,
            'destination_type' => $destination->getMorphClass(),
        ]);

        $this->application = Application::create([
            'name' => 'resources-sensitive-app',
            'git_repository' => 'https://github.com/test/test',
            'git_branch' => 'main',
            'build_pack' => 'nixpacks',
            'ports_exposes' => '3000',
            'http_basic_auth_password' => 'basic-auth-secret',
            'environment_id' => $this->environment->id,
            'destination_id' => $destination->id,
            'destination_type' => $destination->getMorphClass(),
        ]);
    });

    test('read token does not leak database or application secrets', function () {
        $token = makeApiToken($this->user, $this->team, ['read']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v1/resources');

        $response->assertStatus(200);

        $body = $response->getContent();
        expect($body)->not->toContain('postgres_password');
        expect($body)->not->toContain('internal_db_url');
        expect($body)->not->toContain('external_db_url');
        expect($body)->not->toContain('http_basic_auth_password');
    });

    test('read sensitive token sees database and application secrets', function () {
        $token = makeApiToken($this->user, $this->team, ['read', 'read:sensitive']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v1/resources');

        $response->assertStatus(200);

        $body = $response->getContent();
        expect($body)->toContain('"postgres_password":');
        expect($body)->toContain('"internal_db_url":');
        expect($body)->toContain('"http_basic_auth_password":');
    });

    test('root token sees database secrets', function () {
        $token = makeApiToken($this->user, $this->team, ['root']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v1/resources');

        $response->assertStatus(200);

        expect($response->getContent())->toContain('"postgres_password":');
    });
});

describe('GET /api/v1/projects/{uuid}/{environment} sensitive field gating', function () {
    beforeEach(function () {
        $this->project = Project::factory()->create(['team_id' => $this->team->id]);
        $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
        $destination = $this->server->standaloneDockers()->firstOrFail();

        $this->database = StandalonePostgresql::create([
            'name' => 'environment-sensitive-db',
            'postgres_user' => 'postgres',
            'postgres_password' => encrypt('super-secret-db-password'),
            'postgres_db' => 'app',
            'image' => 'postgres:16-alpine',
            'environment_id' => $this->environment->id,
            'destination_id' => $destination->id,
            'destination_type' => $destination->getMorphClass(),
        ]);

        $this->application = Application::create([
            'name' => 'environment-sensitive-app',
            'git_repository' => 'https://github.com/test/test',
            'git_branch' => 'main',
            'build_pack' => 'nixpacks',
            'ports_exposes' => '3000',
            'http_basic_auth_password' => 'basic-auth-secret',
            'environment_id' => $this->environment->id,
            'destination_id' => $destination->id,
            'destination_type' => $destination->getMorphClass(),
        ]);
    });

    test('read token does not leak database or application secrets', function () {
        $token = makeApiToken($this->user, $this->team, ['read']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson("/api/v1/projects/{$this->project->uuid}/{$this->environment->name}");

        $response->assertStatus(200);

        $body = $response->getContent();
        expect($body)->not->toContain('postgres_password');
        expect($body)->not->toContain('internal_db_url');
        expect($body)->not->toContain('external_db_url');
        expect($body)->not->toContain('http_basic_auth_password');
    });

    test('read sensitive token sees database and application secrets', function () {
        $token = makeApiToken($this->user, $this->team, ['read', 'read:sensitive']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson("/api/v1/projects/{$this->project->uuid}/{$this->environment->name}");

        $response->assertStatus(200);

        $body = $response->getContent();
        expect($body)->toContain('"postgres_password":');
        expect($body)->toContain('"internal_db_url":');
        expect($body)->toContain('"http_basic_auth_password":');
    });
});

describe('instance admin team (id 0) sensitive gating', function () {
    test('read sensitive token owned by team 0 sees sensitive fields', function () {
        $team = Team::factory()->create(['id' => 0]);
        $user = User::factory()->create();
        $team->members()->attach($user->id, ['role' => 'owner']);
        session(['currentTeam' => $team]);

        $server = Server::factory()->create(['team_id' => $team->id]);
        $server->settings->forceFill([
            'sentinel_token' => encrypt('team-zero-sentinel-token'),
        ])->saveQuietly();

        $token = makeApiToken($user, $team, ['read', 'read:sensitive']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v1/servers');

        $response->assertStatus(200);

        expect($response->getContent())->toContain('"sentinel_token":');
    });
});
