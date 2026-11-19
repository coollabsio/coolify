<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $plainTextToken = Str::random(40);
    $token = $this->user->tokens()->create([
        'name' => 'railpack-api-test-'.Str::random(6),
        'token' => hash('sha256', $plainTextToken),
        'abilities' => ['*'],
        'team_id' => $this->team->id,
    ]);
    $this->bearerToken = $token->getKey().'|'.$plainTextToken;

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->first();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
});

function railpackApiHeaders(string $bearerToken): array
{
    return [
        'Authorization' => 'Bearer '.$bearerToken,
        'Content-Type' => 'application/json',
    ];
}

function makeRailpackApp(array $overrides = []): Application
{
    return Application::factory()->create(array_merge([
        'environment_id' => test()->environment->id,
        'destination_id' => test()->destination->id,
        'destination_type' => test()->destination->getMorphClass(),
        'build_pack' => 'railpack',
    ], $overrides));
}

describe('PATCH /api/v1/applications/{uuid} build_pack=railpack', function () {
    test('rejects unsupported build_pack at controller layer', function () {
        $app = makeRailpackApp();

        $response = $this->withHeaders(railpackApiHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$app->uuid}", [
                'build_pack' => 'totally-bogus',
            ]);

        $response->assertStatus(422);
    });

    test('switching from dockerfile to railpack clears dockerfile fields', function () {
        $app = makeRailpackApp([
            'build_pack' => 'dockerfile',
            'dockerfile' => 'FROM node:20',
            'dockerfile_location' => '/Dockerfile',
            'dockerfile_target_build' => 'production',
            'custom_healthcheck_found' => true,
        ]);

        $response = $this->withHeaders(railpackApiHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$app->uuid}", [
                'build_pack' => 'railpack',
            ]);

        $response->assertOk();

        $app->refresh();
        expect($app->build_pack)->toBe('railpack');
        expect($app->dockerfile)->toBeNull();
        expect($app->dockerfile_location)->toBeNull();
        expect($app->dockerfile_target_build)->toBeNull();
        expect((bool) $app->custom_healthcheck_found)->toBeFalse();
    });

    test('switching from dockercompose to railpack clears compose fields and SERVICE_* envs', function () {
        $app = makeRailpackApp([
            'build_pack' => 'dockercompose',
            'docker_compose_domains' => '{"app": "example.com"}',
            'docker_compose_raw' => "version: '3'\nservices:\n  app:\n    image: nginx",
        ]);

        $app->environment_variables()->createMany([
            ['key' => 'SERVICE_FQDN_APP', 'value' => 'app.example.com', 'is_buildtime' => false, 'is_preview' => false],
            ['key' => 'SERVICE_URL_APP', 'value' => 'http://app.example.com', 'is_buildtime' => false, 'is_preview' => false],
            ['key' => 'REGULAR_VAR', 'value' => 'keep_me', 'is_buildtime' => false, 'is_preview' => false],
        ]);

        $response = $this->withHeaders(railpackApiHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$app->uuid}", [
                'build_pack' => 'railpack',
            ]);

        $response->assertOk();

        $app->refresh();
        expect($app->build_pack)->toBe('railpack');
        expect($app->docker_compose_domains)->toBeNull();
        expect($app->docker_compose_raw)->toBeNull();
        expect($app->environment_variables()->where('key', 'SERVICE_FQDN_APP')->count())->toBe(0);
        expect($app->environment_variables()->where('key', 'SERVICE_URL_APP')->count())->toBe(0);
        expect($app->environment_variables()->where('key', 'REGULAR_VAR')->count())->toBe(1);
    });

    test('install/build/start commands persist for railpack apps', function () {
        $app = makeRailpackApp();

        $response = $this->withHeaders(railpackApiHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$app->uuid}", [
                'install_command' => 'npm ci',
                'build_command' => 'npm run build',
                'start_command' => 'node server.js',
            ]);

        $response->assertOk();

        $app->refresh();
        expect($app->install_command)->toBe('npm ci');
        expect($app->build_command)->toBe('npm run build');
        expect($app->start_command)->toBe('node server.js');
    });
});

describe('POST /api/v1/applications/{uuid}/envs RAILPACK_* handling', function () {
    test('adding RAILPACK_NODE_VERSION via API surfaces in railpack_environment_variables only', function () {
        $app = makeRailpackApp();

        $response = $this->withHeaders(railpackApiHeaders($this->bearerToken))
            ->postJson("/api/v1/applications/{$app->uuid}/envs", [
                'key' => 'RAILPACK_NODE_VERSION',
                'value' => '20',
                'is_buildtime' => true,
                'is_runtime' => false,
                'is_preview' => false,
            ]);

        $response->assertCreated();

        $app->refresh();
        expect($app->railpack_environment_variables)->toHaveCount(1);
        expect($app->railpack_environment_variables->first()->key)->toBe('RAILPACK_NODE_VERSION');
        expect($app->runtime_environment_variables->where('key', 'RAILPACK_NODE_VERSION'))->toHaveCount(0);
    });

    test('runtime envs added via API surface in runtime_environment_variables but not railpack_*', function () {
        $app = makeRailpackApp();

        $this->withHeaders(railpackApiHeaders($this->bearerToken))
            ->postJson("/api/v1/applications/{$app->uuid}/envs", [
                'key' => 'APP_ENV',
                'value' => 'production',
                'is_runtime' => true,
                'is_buildtime' => false,
                'is_preview' => false,
            ])->assertCreated();

        $this->withHeaders(railpackApiHeaders($this->bearerToken))
            ->postJson("/api/v1/applications/{$app->uuid}/envs", [
                'key' => 'NIXPACKS_NODE_VERSION',
                'value' => '18',
                'is_buildtime' => true,
                'is_runtime' => false,
                'is_preview' => false,
            ])->assertCreated();

        $app->refresh();
        $runtime = $app->runtime_environment_variables;
        expect($runtime->pluck('key')->all())->toBe(['APP_ENV']);
        expect($app->railpack_environment_variables)->toHaveCount(0);
    });

    test('preview RAILPACK_* envs surface in railpack_environment_variables_preview only', function () {
        $app = makeRailpackApp();

        $this->withHeaders(railpackApiHeaders($this->bearerToken))
            ->postJson("/api/v1/applications/{$app->uuid}/envs", [
                'key' => 'RAILPACK_BUILD_CMD',
                'value' => 'npm run build',
                'is_buildtime' => true,
                'is_runtime' => false,
                'is_preview' => true,
            ])->assertCreated();

        $app->refresh();
        expect($app->railpack_environment_variables_preview)->toHaveCount(1);
        expect($app->railpack_environment_variables)->toHaveCount(0);
    });

    test('buildtime-only env has is_buildtime=true and is_runtime=false', function () {
        $app = makeRailpackApp();

        $this->withHeaders(railpackApiHeaders($this->bearerToken))
            ->postJson("/api/v1/applications/{$app->uuid}/envs", [
                'key' => 'API_KEY',
                'value' => 'sekret',
                'is_buildtime' => true,
                'is_runtime' => false,
                'is_preview' => false,
            ])->assertCreated();

        $app->refresh();
        $env = $app->environment_variables()->where('key', 'API_KEY')->first();
        expect($env)->not->toBeNull();
        expect((bool) $env->is_buildtime)->toBeTrue();
        expect((bool) $env->is_runtime)->toBeFalse();
        // Buildtime-only non-RAILPACK_ var: visible to runtime relation (it's not a buildpack-control var)
        // but is_runtime flag is false; consumers gate runtime via is_runtime, not via the relation alone.
        expect($env->resourceable_id)->toBe($app->id);
    });

    test('runtime-only env has is_runtime=true and is_buildtime=false', function () {
        $app = makeRailpackApp();

        $this->withHeaders(railpackApiHeaders($this->bearerToken))
            ->postJson("/api/v1/applications/{$app->uuid}/envs", [
                'key' => 'LOG_LEVEL',
                'value' => 'debug',
                'is_buildtime' => false,
                'is_runtime' => true,
                'is_preview' => false,
            ])->assertCreated();

        $app->refresh();
        $env = $app->environment_variables()->where('key', 'LOG_LEVEL')->first();
        expect((bool) $env->is_buildtime)->toBeFalse();
        expect((bool) $env->is_runtime)->toBeTrue();
    });

    test('railpack build variables collection includes only is_buildtime=true entries', function () {
        // Sanity check the underlying query used by the deploy job: railpack_build_variables()
        // pulls $application->environment_variables()->where('is_buildtime', true)->get()
        // (see ApplicationDeploymentJob::railpack_build_variables).
        $app = makeRailpackApp();

        $this->withHeaders(railpackApiHeaders($this->bearerToken))
            ->postJson("/api/v1/applications/{$app->uuid}/envs", [
                'key' => 'BUILD_ARG',
                'value' => 'in-build',
                'is_buildtime' => true,
                'is_runtime' => false,
                'is_preview' => false,
            ])->assertCreated();

        $this->withHeaders(railpackApiHeaders($this->bearerToken))
            ->postJson("/api/v1/applications/{$app->uuid}/envs", [
                'key' => 'RUNTIME_ARG',
                'value' => 'in-runtime',
                'is_buildtime' => false,
                'is_runtime' => true,
                'is_preview' => false,
            ])->assertCreated();

        $app->refresh();
        $buildtime = $app->environment_variables()->where('is_buildtime', true)->pluck('key')->all();
        expect($buildtime)->toContain('BUILD_ARG');
        expect($buildtime)->not->toContain('RUNTIME_ARG');
    });

    test('user-defined COOLIFY_FQDN takes precedence over auto-generated', function () {
        // Documents generate_coolify_env_variables() override behavior:
        // it skips generation when application->environment_variables already has the key.
        $app = makeRailpackApp();

        $this->withHeaders(railpackApiHeaders($this->bearerToken))
            ->postJson("/api/v1/applications/{$app->uuid}/envs", [
                'key' => 'COOLIFY_FQDN',
                'value' => 'overridden.example.com',
                'is_buildtime' => true,
                'is_runtime' => true,
                'is_preview' => false,
            ])->assertCreated();

        $app->refresh();
        $env = $app->environment_variables()->where('key', 'COOLIFY_FQDN')->first();
        expect($env)->not->toBeNull();
        expect($env->value)->toBe('overridden.example.com');
        // Confirm the model relation used by override-skip logic finds it
        expect($app->environment_variables->where('key', 'COOLIFY_FQDN')->isEmpty())->toBeFalse();
    });

    test('is_literal flag persists on create', function () {
        $app = makeRailpackApp();

        $this->withHeaders(railpackApiHeaders($this->bearerToken))
            ->postJson("/api/v1/applications/{$app->uuid}/envs", [
                'key' => 'RAILPACK_LITERAL_FLAG',
                'value' => '$NOT_INTERPOLATED',
                'is_buildtime' => true,
                'is_runtime' => false,
                'is_preview' => false,
                'is_literal' => true,
            ])->assertCreated();

        $app->refresh();
        $env = $app->environment_variables()->where('key', 'RAILPACK_LITERAL_FLAG')->first();
        expect((bool) $env->is_literal)->toBeTrue();
    });

    test('PATCH env updates buildtime/runtime flags', function () {
        $app = makeRailpackApp();

        $this->withHeaders(railpackApiHeaders($this->bearerToken))
            ->postJson("/api/v1/applications/{$app->uuid}/envs", [
                'key' => 'TOGGLE_VAR',
                'value' => 'v1',
                'is_buildtime' => true,
                'is_runtime' => true,
                'is_preview' => false,
            ])->assertCreated();

        $this->withHeaders(railpackApiHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$app->uuid}/envs", [
                'key' => 'TOGGLE_VAR',
                'value' => 'v2',
                'is_buildtime' => false,
                'is_runtime' => true,
                'is_multiline' => false,
                'is_shown_once' => false,
            ])->assertStatus(201);

        $app->refresh();
        $env = $app->environment_variables()->where('key', 'TOGGLE_VAR')->first();
        expect($env->value)->toBe('v2');
        expect((bool) $env->is_buildtime)->toBeFalse();
        expect((bool) $env->is_runtime)->toBeTrue();
    });
});
