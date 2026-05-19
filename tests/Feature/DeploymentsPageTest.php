<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Livewire\Deployments\Index;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Visus\Cuid2\Cuid2;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    InstanceSettings::unguarded(function () {
        InstanceSettings::query()->create([
            'id' => 0,
            'is_registration_enabled' => true,
        ]);
    });

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    config([
        'app.maintenance.driver' => 'file',
        'cache.default' => 'array',
    ]);

    $this->withoutVite();
});

it('shows team-wide deployments from the sidebar page without leaking other teams', function () {
    [$application, $server, $project] = createDeploymentPageApplication($this->team, 'Checkout API');
    [$otherApplication, $otherServer] = createDeploymentPageApplication(Team::factory()->create(), 'Other Team API');

    ApplicationDeploymentQueue::create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'deployment_uuid' => 'current-team-deployment',
        'deployment_url' => "/project/{$project->uuid}/environment/{$application->environment->uuid}/application/{$application->uuid}/deployment/current-team-deployment",
        'commit' => '1234567890abcdef',
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'finished_at' => now(),
    ]);

    ApplicationDeploymentQueue::create([
        'application_id' => $otherApplication->id,
        'application_name' => $otherApplication->name,
        'server_id' => $otherServer->id,
        'server_name' => $otherServer->name,
        'deployment_uuid' => 'other-team-deployment',
        'status' => ApplicationDeploymentStatus::FAILED->value,
    ]);

    $response = $this->get(route('deployments.index'));

    $response->assertSuccessful();
    $response->assertSee('Deployments');
    $response->assertSee('Checkout API');
    $response->assertSee('Done');
    $response->assertSee('current-team-deployment');
    $response->assertDontSee('Other Team API');
    $response->assertDontSee('other-team-deployment');
});

it('filters deployments by project server source and status', function () {
    $source = GithubApp::query()->create([
        'uuid' => (string) new Cuid2,
        'name' => 'Production GitHub',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'team_id' => $this->team->id,
    ]);

    [$application, $server, $project] = createDeploymentPageApplication($this->team, 'Filtered API', [
        'source_id' => $source->id,
        'source_type' => GithubApp::class,
    ]);

    [$otherApplication, $otherServer] = createDeploymentPageApplication($this->team, 'Unfiltered API');

    ApplicationDeploymentQueue::create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'deployment_uuid' => 'matching-deployment',
        'commit' => 'abcdef1234567890',
        'status' => ApplicationDeploymentStatus::QUEUED->value,
    ]);

    ApplicationDeploymentQueue::create([
        'application_id' => $otherApplication->id,
        'application_name' => $otherApplication->name,
        'server_id' => $otherServer->id,
        'server_name' => $otherServer->name,
        'deployment_uuid' => 'non-matching-deployment',
        'status' => ApplicationDeploymentStatus::FINISHED->value,
    ]);

    Livewire::test(Index::class)
        ->set('project', (string) $project->id)
        ->set('server', (string) $server->id)
        ->set('source', GithubApp::class.':'.$source->id)
        ->set('status', ApplicationDeploymentStatus::QUEUED->value)
        ->assertSee('Filtered API')
        ->assertSee('matching-deployment')
        ->assertSee('Production GitHub')
        ->assertSee('Queued')
        ->assertDontSee('Unfiltered API')
        ->assertDontSee('non-matching-deployment');
});

it('hides server and source filters until there are multiple options', function () {
    [$application, $server] = createDeploymentPageApplication($this->team, 'Only Option API');

    ApplicationDeploymentQueue::create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'deployment_uuid' => 'only-option-deployment',
        'status' => ApplicationDeploymentStatus::FINISHED->value,
    ]);

    Livewire::test(Index::class)
        ->assertSee('Only Option API')
        ->assertDontSee('wire:model=server', false)
        ->assertDontSee('wire:model=source', false);

    $source = GithubApp::query()->create([
        'uuid' => (string) new Cuid2,
        'name' => 'Production GitHub',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'team_id' => $this->team->id,
    ]);

    [$secondApplication, $secondServer] = createDeploymentPageApplication($this->team, 'Second Option API', [
        'source_id' => $source->id,
        'source_type' => GithubApp::class,
    ]);

    ApplicationDeploymentQueue::create([
        'application_id' => $secondApplication->id,
        'application_name' => $secondApplication->name,
        'server_id' => $secondServer->id,
        'server_name' => $secondServer->name,
        'deployment_uuid' => 'second-option-deployment',
        'status' => ApplicationDeploymentStatus::QUEUED->value,
    ]);

    Livewire::test(Index::class)
        ->assertSee('Only Option API')
        ->assertSee('Second Option API')
        ->assertSee('wire:model=server', false)
        ->assertSee('wire:model=source', false);
});

it('adds deployments to the main sidebar navigation', function () {
    $navbar = file_get_contents(resource_path('views/components/navbar.blade.php'));
    $routes = file_get_contents(base_path('routes/web.php'));

    expect($routes)
        ->toContain("Route::get('/deployments', DeploymentsIndex::class)->name('deployments.index')");

    expect($navbar)
        ->toContain('Deployments')
        ->toContain("route('deployments.index')")
        ->toContain("request()->is('deployments*')");
});

function createDeploymentPageApplication(Team $team, string $applicationName, array $overrides = []): array
{
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $application = Application::factory()->create(array_merge([
        'name' => $applicationName,
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'status' => 'running',
    ], $overrides));

    return [$application->load('environment.project'), $server, $project];
}
