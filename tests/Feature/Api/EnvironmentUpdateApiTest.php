<?php

use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    $this->bearerToken = environmentUpdateApiToken($this->user, $this->team, ['*']);
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = $this->project->environments()->first()
        ?? Environment::factory()->create(['project_id' => $this->project->id, 'name' => 'production']);
});

function environmentUpdateApiHeaders(string $bearerToken): array
{
    return [
        'Authorization' => 'Bearer '.$bearerToken,
        'Content-Type' => 'application/json',
    ];
}

function environmentUpdateApiToken(User $user, Team $team, array $abilities): string
{
    $plainTextToken = Str::random(40);
    $token = $user->tokens()->create([
        'name' => 'environment-update-api-test-'.Str::random(6),
        'token' => hash('sha256', $plainTextToken),
        'abilities' => $abilities,
        'team_id' => $team->id,
    ]);

    return $token->getKey().'|'.$plainTextToken;
}

describe('PATCH /api/v1/projects/{uuid}/environments/{environment_name_or_uuid}', function () {
    test('updates environment name and description by uuid', function () {
        $response = $this->withHeaders(environmentUpdateApiHeaders($this->bearerToken))
            ->patchJson("/api/v1/projects/{$this->project->uuid}/environments/{$this->environment->uuid}", [
                'name' => 'staging',
                'description' => 'Staging environment',
            ]);

        $response->assertOk()
            ->assertJson([
                'uuid' => $this->environment->uuid,
                'name' => 'staging',
                'description' => 'Staging environment',
            ]);

        $this->environment->refresh();
        expect($this->environment->name)->toBe('staging')
            ->and($this->environment->description)->toBe('Staging environment');
    });

    test('updates environment by name path segment', function () {
        $response = $this->withHeaders(environmentUpdateApiHeaders($this->bearerToken))
            ->patchJson("/api/v1/projects/{$this->project->uuid}/environments/{$this->environment->name}", [
                'description' => 'Updated description only',
            ]);

        $response->assertOk()
            ->assertJson([
                'uuid' => $this->environment->uuid,
                'name' => $this->environment->name,
                'description' => 'Updated description only',
            ]);
    });

    test('requires a write token', function () {
        $readOnlyToken = environmentUpdateApiToken($this->user, $this->team, ['read']);

        $response = $this->withHeaders(environmentUpdateApiHeaders($readOnlyToken))
            ->patchJson("/api/v1/projects/{$this->project->uuid}/environments/{$this->environment->uuid}", [
                'name' => 'should-fail',
            ]);

        $response->assertForbidden();
        expect($this->environment->fresh()->name)->toBe($this->environment->name);
    });

    test('rejects update requests from non-admin team members', function () {
        $member = User::factory()->create();
        $this->team->members()->attach($member->id, ['role' => 'member']);
        $memberToken = environmentUpdateApiToken($member, $this->team, ['*']);

        $response = $this->withHeaders(environmentUpdateApiHeaders($memberToken))
            ->patchJson("/api/v1/projects/{$this->project->uuid}/environments/{$this->environment->uuid}", [
                'name' => 'member-rename',
            ]);

        $response->assertForbidden();
        expect($this->environment->fresh()->name)->toBe($this->environment->name);
    });

    test('rejects unknown fields', function () {
        $response = $this->withHeaders(environmentUpdateApiHeaders($this->bearerToken))
            ->patchJson("/api/v1/projects/{$this->project->uuid}/environments/{$this->environment->uuid}", [
                'name' => 'valid-name',
                'unexpected' => 'value',
            ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['unexpected']);
    });

    test('returns 404 for another team project', function () {
        $otherTeam = Team::factory()->create();
        $otherProject = Project::factory()->create(['team_id' => $otherTeam->id]);
        $otherEnvironment = $otherProject->environments()->first()
            ?? Environment::factory()->create(['project_id' => $otherProject->id]);

        $response = $this->withHeaders(environmentUpdateApiHeaders($this->bearerToken))
            ->patchJson("/api/v1/projects/{$otherProject->uuid}/environments/{$otherEnvironment->uuid}", [
                'name' => 'stolen',
            ]);

        $response->assertNotFound();
    });

    test('returns 409 when renaming to an existing environment name', function () {
        $other = Environment::factory()->create([
            'project_id' => $this->project->id,
            'name' => 'already-taken',
        ]);

        $response = $this->withHeaders(environmentUpdateApiHeaders($this->bearerToken))
            ->patchJson("/api/v1/projects/{$this->project->uuid}/environments/{$this->environment->uuid}", [
                'name' => $other->name,
            ]);

        $response->assertStatus(409)
            ->assertJson(['message' => 'Environment with this name already exists.']);
    });

    test('returns 404 for missing environment', function () {
        $response = $this->withHeaders(environmentUpdateApiHeaders($this->bearerToken))
            ->patchJson("/api/v1/projects/{$this->project->uuid}/environments/missing-env", [
                'name' => 'new-name',
            ]);

        $response->assertNotFound();
    });
});
