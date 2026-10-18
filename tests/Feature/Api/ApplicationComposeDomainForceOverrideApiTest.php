<?php

use App\Models\Application;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Visus\Cuid2\Cuid2;

uses(RefreshDatabase::class);

// create_application() strips force_domain_override from the request before the
// docker_compose_domains checks run, so the flag must be read up front.

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

function createComposeApplication(string $endpoint, array $composeDomains, bool $force)
{
    $payload = [
        'project_uuid' => test()->project->uuid,
        'environment_uuid' => test()->environment->uuid,
        'server_uuid' => test()->server->uuid,
        'git_branch' => 'v4.x',
        'build_pack' => 'dockercompose',
        'docker_compose_domains' => $composeDomains,
        'force_domain_override' => $force,
    ];

    if ($endpoint === 'private-deploy-key') {
        $payload['private_key_uuid'] = PrivateKey::factory()->create(['team_id' => test()->team->id])->uuid;
        $payload['git_repository'] = 'git@gitlab.com:coollabsio/coolify-examples.git';
    } else {
        // Not github.com: that path looks up the seeded public GithubApp (id 0).
        $payload['git_repository'] = 'https://gitlab.com/coollabsio/coolify-examples';
    }

    return test()->withToken(test()->bearerToken)->postJson("/api/v1/applications/{$endpoint}", $payload);
}

dataset('endpoints', ['public', 'private-deploy-key']);

dataset('conflicts', [
    'domain used by another application' => [
        [['name' => 'web', 'domain' => 'https://shared.example.com']],
        409,
    ],
    'same domain twice in the request' => [
        [
            ['name' => 'web', 'domain' => 'https://dup.example.com'],
            ['name' => 'worker', 'domain' => 'https://dup.example.com'],
        ],
        422,
    ],
]);

it('refuses conflicting docker_compose_domains without force_domain_override', function (string $endpoint, array $composeDomains, int $status) {
    createComposeApplication($endpoint, $composeDomains, false)
        ->assertStatus($status)
        ->assertSeeText('Use force_domain_override=true to proceed.');
})->with('endpoints')->with('conflicts');

it('creates the application with conflicting docker_compose_domains when force_domain_override is true', function (string $endpoint, array $composeDomains) {
    $response = createComposeApplication($endpoint, $composeDomains, true)->assertCreated();

    $application = Application::where('uuid', $response->json('uuid'))->firstOrFail();
    $savedDomains = collect(json_decode($application->docker_compose_domains, true))
        ->map(fn ($entry) => $entry['domain']);

    expect($savedDomains->all())->toBe(collect($composeDomains)->pluck('domain', 'name')->all());
})->with('endpoints')->with('conflicts');
