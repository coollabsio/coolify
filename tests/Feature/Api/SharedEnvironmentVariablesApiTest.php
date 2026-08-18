<?php

use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\SharedEnvironmentVariable;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'cache.default' => 'array',
        'session.driver' => 'array',
        'queue.default' => 'sync',
        'app.maintenance.driver' => 'file',
    ]);

    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0], ['is_api_enabled' => true]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    session(['currentTeam' => $this->team]);

    $this->token = $this->user->createToken('test-token', ['*']);
    $this->bearerToken = $this->token->plainTextToken;

    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
});

function sharedEnvHeaders(string $bearerToken): array
{
    return [
        'Authorization' => 'Bearer '.$bearerToken,
        'Content-Type' => 'application/json',
    ];
}

describe('Team shared environment variables API', function () {
    test('creates lists updates and deletes team shared envs', function () {
        $create = $this->withHeaders(sharedEnvHeaders($this->bearerToken))
            ->postJson('/api/v1/team/envs', [
                'key' => 'TEAM_API_KEY',
                'value' => 'secret-value',
                'is_literal' => true,
                'comment' => 'from api',
            ]);

        $create->assertStatus(201);
        $create->assertJsonStructure(['id']);
        $envId = $create->json('id');

        expect(SharedEnvironmentVariable::query()->whereKey($envId)->first())
            ->type->toBe('team')
            ->team_id->toBe($this->team->id)
            ->project_id->toBeNull()
            ->key->toBe('TEAM_API_KEY');

        $list = $this->withHeaders(sharedEnvHeaders($this->bearerToken))
            ->getJson('/api/v1/team/envs');

        $list->assertOk();
        $list->assertJsonFragment(['key' => 'TEAM_API_KEY', 'id' => $envId]);

        $update = $this->withHeaders(sharedEnvHeaders($this->bearerToken))
            ->patchJson("/api/v1/team/envs/{$envId}", [
                'value' => 'updated-value',
                'is_multiline' => true,
            ]);

        $update->assertOk();
        $update->assertJsonFragment(['key' => 'TEAM_API_KEY']);
        expect($update->json('is_multiline'))->toBeTruthy();

        $delete = $this->withHeaders(sharedEnvHeaders($this->bearerToken))
            ->deleteJson("/api/v1/team/envs/{$envId}");

        $delete->assertOk();
        $delete->assertJson(['message' => 'Environment variable deleted.']);
        expect(SharedEnvironmentVariable::query()->whereKey($envId)->exists())->toBeFalse();
    });

    test('hides value without can_read_sensitive', function () {
        SharedEnvironmentVariable::create([
            'key' => 'HIDDEN_SECRET',
            'value' => 'should-not-appear',
            'type' => 'team',
            'team_id' => $this->team->id,
        ]);

        $readToken = $this->user->createToken('read-token', ['read'])->plainTextToken;

        $response = $this->withHeaders(sharedEnvHeaders($readToken))
            ->getJson('/api/v1/team/envs');

        $response->assertOk();
        $response->assertJsonFragment(['key' => 'HIDDEN_SECRET']);
        expect($response->json('0'))->not->toHaveKey('value');
    });

    test('returns 409 when creating duplicate team key', function () {
        SharedEnvironmentVariable::create([
            'key' => 'DUP_KEY',
            'value' => 'one',
            'type' => 'team',
            'team_id' => $this->team->id,
        ]);

        $response = $this->withHeaders(sharedEnvHeaders($this->bearerToken))
            ->postJson('/api/v1/team/envs', [
                'key' => 'DUP_KEY',
                'value' => 'two',
            ]);

        $response->assertStatus(409);
    });
});

describe('Project shared environment variables API', function () {
    test('creates and lists project shared envs', function () {
        $create = $this->withHeaders(sharedEnvHeaders($this->bearerToken))
            ->postJson("/api/v1/projects/{$this->project->uuid}/envs", [
                'key' => 'PROJECT_VAR',
                'value' => 'project-secret',
            ]);

        $create->assertStatus(201);
        $envId = $create->json('id');

        $env = SharedEnvironmentVariable::query()->whereKey($envId)->first();
        expect($env)
            ->type->toBe('project')
            ->project_id->toBe($this->project->id)
            ->team_id->toBe($this->team->id);

        $list = $this->withHeaders(sharedEnvHeaders($this->bearerToken))
            ->getJson("/api/v1/projects/{$this->project->uuid}/envs");

        $list->assertOk();
        $list->assertJsonFragment(['key' => 'PROJECT_VAR', 'id' => $envId]);
    });

    test('returns 404 for project from another team', function () {
        $otherTeam = Team::factory()->create();
        $otherProject = Project::factory()->create(['team_id' => $otherTeam->id]);

        SharedEnvironmentVariable::create([
            'key' => 'OTHER_TEAM_VAR',
            'value' => 'nope',
            'type' => 'project',
            'team_id' => $otherTeam->id,
            'project_id' => $otherProject->id,
        ]);

        $list = $this->withHeaders(sharedEnvHeaders($this->bearerToken))
            ->getJson("/api/v1/projects/{$otherProject->uuid}/envs");
        $list->assertStatus(404);

        $create = $this->withHeaders(sharedEnvHeaders($this->bearerToken))
            ->postJson("/api/v1/projects/{$otherProject->uuid}/envs", [
                'key' => 'SHOULD_FAIL',
                'value' => 'x',
            ]);
        $create->assertStatus(404);
    });

    test('returns 404 when updating env from another team scope', function () {
        $otherTeam = Team::factory()->create();
        $otherProject = Project::factory()->create(['team_id' => $otherTeam->id]);
        $otherEnv = SharedEnvironmentVariable::create([
            'key' => 'FOREIGN',
            'value' => 'secret',
            'type' => 'project',
            'team_id' => $otherTeam->id,
            'project_id' => $otherProject->id,
        ]);

        $response = $this->withHeaders(sharedEnvHeaders($this->bearerToken))
            ->patchJson("/api/v1/projects/{$this->project->uuid}/envs/{$otherEnv->id}", [
                'value' => 'hacked',
            ]);

        $response->assertStatus(404);
    });
});
