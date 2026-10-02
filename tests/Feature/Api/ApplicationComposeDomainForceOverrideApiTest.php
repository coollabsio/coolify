<?php

use App\Jobs\LoadComposeFile;
use App\Models\Application;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Visus\Cuid2\Cuid2;

uses(RefreshDatabase::class);

// A sibling application already serves https://shared.example.com. Creating a
// Docker Compose application whose docker_compose_domains names the same
// hostname must succeed when the request sets force_domain_override, exactly as
// it does for `domains` and for docker_compose_domains on PATCH.

beforeEach(function () {
    // The create dispatches LoadComposeFile, which would clone the repository.
    Queue::fake();
    config(['app.maintenance.store' => 'array']);
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    session(['currentTeam' => $this->team]);

    $this->bearerToken = $this->user->createToken('test-token', ['*'])->plainTextToken;

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->server->settings()->update(['is_reachable' => true, 'is_usable' => true]);

    StandaloneDocker::withoutEvents(function () {
        $this->destination = $this->server->standaloneDockers()->firstOrCreate(
            ['network' => 'coolify'],
            ['uuid' => (string) new Cuid2, 'name' => 'test-docker']
        );
    });

    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = $this->project->environments()->first();

    Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'fqdn' => 'https://shared.example.com',
    ]);
});

function createComposeAppOnSharedDomain(string $token, array $context, bool $force)
{
    return test()->withToken($token)->postJson('/api/v1/applications/public', [
        'project_uuid' => $context['project'],
        'environment_uuid' => $context['environment'],
        'server_uuid' => $context['server'],
        // Not github.com: that path looks up the seeded public GithubApp (id 0).
        'git_repository' => 'https://gitlab.com/coollabsio/coolify-examples',
        'git_branch' => 'v4.x',
        'build_pack' => 'dockercompose',
        'docker_compose_domains' => [
            ['name' => 'web', 'domain' => 'https://shared.example.com'],
        ],
        'force_domain_override' => $force,
    ]);
}

it('refuses conflicting docker_compose_domains on create without force_domain_override', function () {
    createComposeAppOnSharedDomain($this->bearerToken, [
        'project' => $this->project->uuid,
        'environment' => $this->environment->uuid,
        'server' => $this->server->uuid,
    ], false)
        ->assertStatus(409)
        ->assertJsonPath('message', 'Domain conflicts detected. Use force_domain_override=true to proceed.');
});

it('creates the application when force_domain_override is true', function () {
    $count = Application::count();

    createComposeAppOnSharedDomain($this->bearerToken, [
        'project' => $this->project->uuid,
        'environment' => $this->environment->uuid,
        'server' => $this->server->uuid,
    ], true)
        ->assertCreated();

    expect(Application::count())->toBe($count + 1);
});
