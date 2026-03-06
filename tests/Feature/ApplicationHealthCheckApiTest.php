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
        $this->destination = StandaloneDocker::firstOrCreate(
            ['server_id' => $this->server->id, 'network' => 'coolify'],
            ['uuid' => (string) new Cuid2, 'name' => 'test-docker']
        );
    });

    $this->project = Project::create([
        'uuid' => (string) new Cuid2,
        'name' => 'test-project',
        'team_id' => $this->team->id,
    ]);

    // Project boot event auto-creates a 'production' environment
    $this->environment = $this->project->environments()->first();

    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
});

function healthCheckAuthHeaders($bearerToken): array
{
    return [
        'Authorization' => 'Bearer '.$bearerToken,
        'Content-Type' => 'application/json',
    ];
}

describe('PATCH /api/v1/applications/{uuid} health check fields', function () {
    test('can update health_check_type to cmd with a command', function () {
        $response = $this->withHeaders(healthCheckAuthHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$this->application->uuid}", [
                'health_check_type' => 'cmd',
                'health_check_command' => 'pg_isready -U postgres',
            ]);

        $response->assertOk();

        $this->application->refresh();
        expect($this->application->health_check_type)->toBe('cmd');
        expect($this->application->health_check_command)->toBe('pg_isready -U postgres');
    });

    test('can update health_check_type back to http', function () {
        $this->application->update([
            'health_check_type' => 'cmd',
            'health_check_command' => 'redis-cli ping',
        ]);

        $response = $this->withHeaders(healthCheckAuthHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$this->application->uuid}", [
                'health_check_type' => 'http',
                'health_check_command' => null,
            ]);

        $response->assertOk();

        $this->application->refresh();
        expect($this->application->health_check_type)->toBe('http');
        expect($this->application->health_check_command)->toBeNull();
    });

    test('rejects invalid health_check_type', function () {
        $response = $this->withHeaders(healthCheckAuthHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$this->application->uuid}", [
                'health_check_type' => 'exec',
            ]);

        $response->assertStatus(422);
    });

    test('rejects health_check_command with shell operators', function () {
        $response = $this->withHeaders(healthCheckAuthHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$this->application->uuid}", [
                'health_check_type' => 'cmd',
                'health_check_command' => 'pg_isready; rm -rf /',
            ]);

        $response->assertStatus(422);
    });

    test('rejects health_check_command over 1000 characters', function () {
        $response = $this->withHeaders(healthCheckAuthHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$this->application->uuid}", [
                'health_check_type' => 'cmd',
                'health_check_command' => str_repeat('a', 1001),
            ]);

        $response->assertStatus(422);
    });
});
