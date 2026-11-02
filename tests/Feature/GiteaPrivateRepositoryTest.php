<?php

use App\Livewire\Project\New\GiteaPrivateRepository;
use App\Models\Application;
use App\Models\Environment;
use App\Models\GiteaApp;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Http::GitSource() pins DNS before Http::fake() answers, so use a public IP
 * literal that needs no DNS lookup. No request leaves the test.
 */
function giteaPickerBaseUrl(): string
{
    return 'https://1.1.1.1';
}

function giteaPickerSourceForTeam(Team $team, string $name): GiteaApp
{
    return GiteaApp::create([
        'name' => $name,
        'api_url' => giteaPickerBaseUrl().'/api/v1',
        'html_url' => giteaPickerBaseUrl(),
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'access_token' => 'gitea-access-token',
        'refresh_token' => 'gitea-refresh-token',
        'expires_at' => time() + 3600,
        'team_id' => $team->id,
        'is_system_wide' => false,
        'is_public' => false,
    ]);
}

beforeEach(function () {
    Queue::fake();
    Server::flushIdentityMap();
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->destination = StandaloneDocker::factory()->create([
        'server_id' => $this->server->id,
        'name' => 'dest-gitea',
        'network' => 'coolify-gitea',
    ]);

    $this->giteaApp = giteaPickerSourceForTeam($this->team, 'Own Gitea');

    $this->otherTeam = Team::factory()->create();
    $this->otherGiteaApp = giteaPickerSourceForTeam($this->otherTeam, 'Foreign Gitea');

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

it('lists only the current team Gitea Apps', function () {
    Livewire::test(GiteaPrivateRepository::class, ['type' => 'private-gitea-app'])
        ->assertSee('Own Gitea')
        ->assertDontSee('Foreign Gitea');
});

it('loads Gitea repositories and branches and creates an application from the selection', function () {
    Http::fake([
        '1.1.1.1/api/v1/user/repos*' => Http::response([
            ['id' => 42, 'name' => 'web', 'full_name' => 'acme/web', 'default_branch' => 'develop', 'html_url' => giteaPickerBaseUrl().'/acme/web', 'owner' => ['login' => 'acme']],
        ], 200),
        '1.1.1.1/api/v1/repos/acme/web/branches*' => Http::response([
            ['name' => 'main'],
            ['name' => 'develop'],
        ], 200),
    ]);

    Livewire::test(GiteaPrivateRepository::class, ['type' => 'private-gitea-app'])
        ->set('parameters', ['project_uuid' => $this->project->uuid, 'environment_uuid' => $this->environment->uuid])
        ->set('query', ['destination' => $this->destination->uuid])
        ->call('loadRepositories', $this->giteaApp->id)
        ->assertSet('current_step', 'repository')
        ->assertSet('selected_project_id', 42)
        ->assertSet('selected_repository_path', 'acme/web')
        ->call('loadBranches')
        ->assertSet('selected_branch_name', 'develop')
        ->call('submit')
        ->assertHasNoErrors();

    $application = Application::query()->sole();
    expect($application->repository_project_id)->toBe(42)
        ->and($application->git_repository)->toBe('acme/web')
        ->and($application->git_branch)->toBe('develop')
        ->and($application->source_type)->toBe(GiteaApp::class)
        ->and($application->source_id)->toBe($this->giteaApp->id);
});

it('rejects another team Gitea App without calling its API', function () {
    Http::fake();

    expect(fn () => Livewire::test(GiteaPrivateRepository::class, ['type' => 'private-gitea-app'])
        ->call('loadRepositories', $this->otherGiteaApp->id))
        ->toThrow(ModelNotFoundException::class);

    Http::assertNothingSent();
    expect(Application::count())->toBe(0);
});

it('locks the selected Gitea App so the client cannot switch it before submit', function () {
    expect(fn () => Livewire::test(GiteaPrivateRepository::class, ['type' => 'private-gitea-app'])
        ->set('gitea_app_id', $this->otherGiteaApp->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});
