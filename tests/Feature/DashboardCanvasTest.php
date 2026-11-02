<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
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
    Server::flushIdentityMap();

    $this->team = Team::factory()->create(['show_boarding' => false]);
    $this->admin = User::factory()->create();
    $this->team->members()->attach($this->admin->id, ['role' => 'admin']);

    $this->server = Server::factory()->create(['team_id' => $this->team->id, 'name' => 'web-server']);
    $this->environment = Environment::factory()->create([
        'project_id' => Project::factory()->create(['team_id' => $this->team->id])->id,
    ]);
});

function dashboardCanvasApp(Environment $environment, Server $server, string $name): Application
{
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();

    return Application::factory()->create([
        'name' => $name,
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
}

function dashboardCanvasDeployment(Application $application, Server $server, ApplicationDeploymentStatus $status): ApplicationDeploymentQueue
{
    return ApplicationDeploymentQueue::query()->forceCreate([
        'application_id' => (string) $application->id,
        'application_name' => $application->name,
        'deployment_uuid' => (string) str()->uuid(),
        'deployment_url' => '/deployment/'.$status->value,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'status' => $status->value,
    ]);
}

function dashboardCanvasSession(object $test, User $user, Team $team): object
{
    return $test->actingAs($user)->withSession(['currentTeam' => $team])
        ->withHeader('X-Canvas-Team', (string) $team->id);
}

test('the dashboard canvas marks applications with an active deployment and lists recent deployments', function () {
    $deploying = dashboardCanvasApp($this->environment, $this->server, 'storefront');
    $queued = dashboardCanvasApp($this->environment, $this->server, 'orders-api');
    $idle = dashboardCanvasApp($this->environment, $this->server, 'docs');
    dashboardCanvasDeployment($deploying, $this->server, ApplicationDeploymentStatus::IN_PROGRESS);
    dashboardCanvasDeployment($queued, $this->server, ApplicationDeploymentStatus::QUEUED);
    dashboardCanvasDeployment($idle, $this->server, ApplicationDeploymentStatus::FINISHED);

    $response = dashboardCanvasSession($this, $this->admin, $this->team)
        ->getJson('/canvas/api/dashboard/canvas')
        ->assertOk()
        ->assertJsonPath('title', 'Dashboard')
        ->assertJsonPath('zones.0.id', $this->server->uuid);
    $nodes = collect($response->json('nodes'))->keyBy('id');

    expect($nodes[$deploying->uuid]['deployment'])->toBe(['status' => 'in_progress', 'link' => '/deployment/in_progress', 'pull_request_id' => null])
        ->and($nodes[$queued->uuid]['deployment']['status'])->toBe('queued')
        ->and($nodes[$idle->uuid])->not->toHaveKey('deployment')
        // The deployment panel lists running and queued deployments first, then the finished ones.
        ->and(collect($response->json('deployments'))->map(fn (array $row) => [$row['application'], $row['status']])->all())->toBe([
            [$deploying->uuid, 'in_progress'],
            [$queued->uuid, 'queued'],
            [$idle->uuid, 'finished'],
        ]);
});

test('the dashboard canvas never shows another team servers, resources, or deployments', function () {
    $otherTeam = Team::factory()->create(['show_boarding' => false]);
    $outsider = User::factory()->create();
    $otherTeam->members()->attach($outsider->id, ['role' => 'owner']);
    $this->team->members()->attach($outsider->id, ['role' => 'member']);
    $app = dashboardCanvasApp($this->environment, $this->server, 'storefront');
    dashboardCanvasDeployment($app, $this->server, ApplicationDeploymentStatus::IN_PROGRESS);

    $response = dashboardCanvasSession($this, $outsider, $otherTeam)
        ->getJson('/canvas/api/dashboard/canvas')
        ->assertOk();

    expect($response->json('zones'))->toBe([])
        ->and($response->json('nodes'))->toBe([])
        ->and($response->json('deployments'))->toBe([]);
});

test('the dashboard opens the canvas again when the user left it there', function () {
    $cookie = "coolify_{$this->admin->id}_dashboard_view";

    dashboardCanvasSession($this, $this->admin, $this->team)
        ->withoutVite()
        ->get(route('dashboard.canvas'))
        ->assertOk()
        ->assertSee('data-endpoint="/dashboard/canvas"', false)
        ->assertCookie($cookie, 'canvas');

    dashboardCanvasSession($this, $this->admin, $this->team)
        ->withCookie($cookie, 'canvas')
        ->get(route('dashboard'))
        ->assertRedirect(route('dashboard.canvas'));

    // The list link on the canvas opens the list and makes it the remembered view.
    dashboardCanvasSession($this, $this->admin, $this->team)
        ->withCookie($cookie, 'canvas')
        ->get(route('dashboard', ['view' => 'list']))
        ->assertOk()
        ->assertCookie($cookie, 'list');
});
