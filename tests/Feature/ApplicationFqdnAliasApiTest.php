<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Visus\Cuid2\Cuid2;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    session(['currentTeam' => $this->team]);

    $this->token = $this->user->createToken('test-token', ['*']);
    $this->bearerToken = $this->token->plainTextToken;

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);

    StandaloneDocker::withoutEvents(function () {
        $this->destination = $this->server->standaloneDockers()->firstOrCreate(
            ['network' => 'coolify'],
            ['uuid' => (string) new Cuid2, 'name' => 'test-docker']
        );
    });

    $this->project = Project::create([
        'uuid' => (string) new Cuid2,
        'name' => 'test-project',
        'team_id' => $this->team->id,
    ]);

    $this->environment = $this->project->environments()->first();
});

function apiAuthHeaders($bearerToken): array
{
    return [
        'Authorization' => 'Bearer '.$bearerToken,
        'Content-Type' => 'application/json',
    ];
}

describe('POST /api/v1/applications/public with fqdn alias', function () {
    test('accepts fqdn as alias for domains in POST request', function () {
        $response = $this->withHeaders(apiAuthHeaders($this->bearerToken))
            ->postJson('/api/v1/applications/public', [
                'project_uuid' => $this->project->uuid,
                'environment_uuid' => $this->environment->uuid,
                'server_uuid' => $this->server->uuid,
                'git_repository' => 'https://github.com/test/test.git',
                'git_branch' => 'main',
                'build_pack' => 'nixpacks',
                'ports_exposes' => '3000',
                'fqdn' => 'https://test.example.com',
            ]);

        $response->assertStatus(201);
    });

    test('accepts domains field in POST request (backward compatibility)', function () {
        $response = $this->withHeaders(apiAuthHeaders($this->bearerToken))
            ->postJson('/api/v1/applications/public', [
                'project_uuid' => $this->project->uuid,
                'environment_uuid' => $this->environment->uuid,
                'server_uuid' => $this->server->uuid,
                'git_repository' => 'https://github.com/test/test.git',
                'git_branch' => 'main',
                'build_pack' => 'nixpacks',
                'ports_exposes' => '3000',
                'domains' => 'https://test.example.com',
            ]);

        $response->assertStatus(201);
    });

    test('rejects fqdn for dockercompose applications', function () {
        $response = $this->withHeaders(apiAuthHeaders($this->bearerToken))
            ->postJson('/api/v1/applications/public', [
                'project_uuid' => $this->project->uuid,
                'environment_uuid' => $this->environment->uuid,
                'server_uuid' => $this->server->uuid,
                'git_repository' => 'https://github.com/test/test.git',
                'git_branch' => 'main',
                'build_pack' => 'dockercompose',
                'ports_exposes' => '80',
                'fqdn' => 'https://test.example.com',
            ]);

        $response->assertStatus(422);
        $response->assertJson([
            'message' => 'Validation failed.',
            'errors' => [
                'domains' => ['The domains field cannot be used for dockercompose applications. Use docker_compose_domains instead to set domains for individual services.'],
            ],
        ]);
    });
});

describe('PATCH /api/v1/applications/{uuid} with fqdn alias', function () {
    beforeEach(function () {
        $this->application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
            'build_pack' => 'nixpacks',
        ]);
    });

    test('accepts fqdn as alias for domains in PATCH request', function () {
        $response = $this->withHeaders(apiAuthHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$this->application->uuid}", [
                'fqdn' => 'https://updated.example.com',
            ]);

        $response->assertOk();

        $this->application->refresh();
        expect($this->application->fqdn)->toBe('https://updated.example.com');
    });

    test('accepts domains field in PATCH request (backward compatibility)', function () {
        $response = $this->withHeaders(apiAuthHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$this->application->uuid}", [
                'domains' => 'https://updated.example.com',
            ]);

        $response->assertOk();

        $this->application->refresh();
        expect($this->application->fqdn)->toBe('https://updated.example.com');
    });

    test('rejects fqdn for dockercompose applications', function () {
        $this->application->update(['build_pack' => 'dockercompose']);

        $response = $this->withHeaders(apiAuthHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$this->application->uuid}", [
                'fqdn' => 'https://updated.example.com',
            ]);

        $response->assertStatus(422);
        $response->assertJson([
            'message' => 'Validation failed.',
            'errors' => [
                'domains' => ['The domains field cannot be used for dockercompose applications. Use docker_compose_domains instead to set domains for individual services.'],
            ],
        ]);
    });

    test('prefers domains over fqdn when both are provided', function () {
        $response = $this->withHeaders(apiAuthHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$this->application->uuid}", [
                'domains' => 'https://domains.example.com',
                'fqdn' => 'https://fqdn.example.com',
            ]);

        $response->assertOk();

        $this->application->refresh();
        expect($this->application->fqdn)->toBe('https://domains.example.com');
    });
});
