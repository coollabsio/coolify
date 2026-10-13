<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\SharedEnvironmentVariable;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0], ['is_api_enabled' => true]));
    Server::flushIdentityMap();

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $this->bearerToken = $this->user->createToken('test-token', ['read', 'read:sensitive'])->plainTextToken;

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);
});

function createLockedSharedReferenceForApiTest(bool $locked): void
{
    SharedEnvironmentVariable::create([
        'key' => 'DB_SECRET',
        'value' => 'locked-shared-secret',
        'type' => 'team',
        'team_id' => test()->team->id,
        'is_shown_once' => $locked,
    ]);
    EnvironmentVariable::create([
        'key' => 'DATABASE_PASSWORD',
        'value' => '{{team.DB_SECRET}}',
        'resourceable_type' => Application::class,
        'resourceable_id' => test()->application->id,
    ]);
}

it('does not reveal a locked shared variable through a reference in the application envs API', function () {
    createLockedSharedReferenceForApiTest(locked: true);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$this->bearerToken])
        ->getJson("/api/v1/applications/{$this->application->uuid}/envs");

    $response->assertOk();
    $env = collect($response->json())->firstWhere('key', 'DATABASE_PASSWORD');
    expect($env['real_value'])->toBe('{{team.DB_SECRET}}')
        ->and($response->getContent())->not->toContain('locked-shared-secret');
});

it('still resolves an unlocked shared variable reference in the application envs API', function () {
    createLockedSharedReferenceForApiTest(locked: false);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$this->bearerToken])
        ->getJson("/api/v1/applications/{$this->application->uuid}/envs");

    $response->assertOk();
    expect(collect($response->json())->firstWhere('key', 'DATABASE_PASSWORD')['real_value'])->toBe('locked-shared-secret');
});
