<?php

use App\Livewire\Project\ProjectCanvas;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\ResourceLink;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

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
    $this->project = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'Shop']);
    $this->staging = Environment::factory()->create(['project_id' => $this->project->id, 'name' => 'staging']);
    $this->production = $this->project->environments()->where('name', 'production')->firstOrFail();
});

function projectCanvasApp(Environment $environment, Server $server, string $name): Application
{
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();

    return Application::factory()->create([
        'name' => $name,
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
}

function projectCanvasSession(object $test, User $user, Team $team): object
{
    return $test->actingAs($user)->withSession(['currentTeam' => $team])
        ->withHeader('X-Canvas-Team', (string) $team->id);
}

test('the project canvas shows the resources of every environment, grouped by environment', function () {
    $app = projectCanvasApp($this->staging, $this->server, 'storefront');
    $api = projectCanvasApp($this->staging, $this->server, 'orders-api');
    $live = projectCanvasApp($this->production, $this->server, 'storefront-live');
    $link = ResourceLink::create([
        'environment_id' => $this->staging->id,
        'source_type' => $app->getMorphClass(), 'source_id' => $app->id,
        'target_type' => $api->getMorphClass(), 'target_id' => $api->id,
    ]);
    $this->production->forceFill(['canvas_positions' => [$live->uuid => ['x' => 40, 'y' => 80]]])->save();

    // A resource of another project of the same team stays out.
    $other = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $this->team->id])->id]);
    projectCanvasApp($other, $this->server, 'elsewhere');

    $response = projectCanvasSession($this, $this->admin, $this->team)
        ->getJson("/canvas/api/projects/{$this->project->uuid}/canvas")
        ->assertOk()
        ->assertJsonPath('title', 'Shop')
        ->assertJsonPath('can_edit', true)
        ->assertJsonPath('can_move', false)
        ->assertJsonPath('can_move_groups', true)
        ->assertJsonPath('layout', 'server')
        ->assertJsonPath('links.0.id', $link->uuid)
        // Auto-placed: a position saved on one environment canvas does not fit next to the other environments.
        ->assertJsonPath('positions', []);

    expect(collect($response->json('zones'))->pluck('name')->all())->toBe(['web-server'])
        ->and(collect($response->json('nodes'))->mapWithKeys(fn ($node) => [$node['name'] => $node['context']])->sortKeys()->all())->toBe([
            'orders-api' => 'staging',
            'storefront' => 'staging',
            'storefront-live' => 'production',
        ])
        // The canvas saves moves and links on the environment canvas of each card.
        ->and(collect($response->json('nodes'))->firstWhere('name', 'storefront-live')['environment'])->toBe(['uuid' => $this->production->uuid, 'name' => 'production']);
});

test('members see the project canvases but cannot edit them', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);

    projectCanvasSession($this, $member, $this->team)
        ->getJson("/canvas/api/projects/{$this->project->uuid}/canvas")
        ->assertOk()
        ->assertJsonPath('can_edit', false);
    projectCanvasSession($this, $member, $this->team)
        ->getJson('/canvas/api/projects/canvas')
        ->assertOk()
        ->assertJsonPath('can_edit', false)
        ->assertJsonPath('can_move_groups', false);
    projectCanvasSession($this, $member, $this->team)
        ->getJson("/canvas/api/projects/{$this->project->uuid}/canvas")
        ->assertJsonPath('can_move_groups', false);
    projectCanvasSession($this, $this->admin, $this->team)
        ->getJson('/canvas/api/projects/canvas')
        ->assertJsonPath('can_move_groups', true);
});

test('the project canvas is out of reach from another team', function () {
    $otherTeam = Team::factory()->create(['show_boarding' => false]);
    $outsider = User::factory()->create();
    $otherTeam->members()->attach($outsider->id, ['role' => 'owner']);
    $this->team->members()->attach($outsider->id, ['role' => 'member']);

    // Session switched to the other team: this team's project is out of reach.
    projectCanvasSession($this, $outsider, $otherTeam)
        ->getJson("/canvas/api/projects/{$this->project->uuid}/canvas")
        ->assertNotFound();
    projectCanvasSession($this, $outsider, $otherTeam)
        ->get(route('project.canvas', ['project_uuid' => $this->project->uuid]))
        ->assertNotFound();
});

test('the project page reopens the canvas when the user left it on the canvas view', function () {
    $parameters = ['project_uuid' => $this->project->uuid];

    projectCanvasSession($this, $this->admin, $this->team)
        ->withoutVite()
        ->get(route('project.canvas', $parameters))
        ->assertOk()
        ->assertSee('data-endpoint="/projects/'.$this->project->uuid.'/canvas"', false)
        ->assertCookie("coolify_{$this->admin->id}_project_view", 'canvas');

    projectCanvasSession($this, $this->admin, $this->team)
        ->withCookie("coolify_{$this->admin->id}_project_view", 'canvas')
        ->get(route('project.show', $parameters))
        ->assertRedirect(route('project.canvas', $parameters));

    // The table / grid links on the canvas pick the list view again.
    projectCanvasSession($this, $this->admin, $this->team)
        ->withCookie("coolify_{$this->admin->id}_project_view", 'canvas')
        ->get(route('project.show', [...$parameters, 'view' => 'grid']))
        ->assertOk()
        ->assertCookie("coolify_{$this->admin->id}_project_view", 'grid');
});

test('the projects canvas shows every project of the team as a zone, grouped by environment', function () {
    $app = projectCanvasApp($this->staging, $this->server, 'storefront');
    $blog = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'Blog']);
    $post = projectCanvasApp($blog->environments()->firstOrFail(), $this->server, 'wordpress');

    // Another team's project stays out.
    $otherTeam = Team::factory()->create();
    $otherServer = Server::factory()->create(['team_id' => $otherTeam->id]);
    $foreign = Project::factory()->create(['team_id' => $otherTeam->id, 'name' => 'Foreign']);
    projectCanvasApp($foreign->environments()->firstOrFail(), $otherServer, 'secret-app');

    $response = projectCanvasSession($this, $this->admin, $this->team)
        ->getJson('/canvas/api/projects/canvas')
        ->assertOk()
        ->assertJsonPath('title', 'Projects')
        ->assertJsonPath('can_edit', true)
        ->assertJsonPath('layout', 'server');

    expect(collect($response->json('zones'))->pluck('name')->all())->toBe(['Blog', 'Shop'])
        ->and(collect($response->json('nodes'))->mapWithKeys(fn ($node) => [$node['name'] => [$node['zone'], $node['context']]])->sortKeys()->all())->toBe([
            'No resources yet' => [$this->project->uuid, 'production'],
            'storefront' => [$this->project->uuid, 'staging'],
            'wordpress' => [$blog->uuid, 'production'],
        ]);
});

test('the project canvases show empty environments and projects as placeholder cards', function () {
    projectCanvasApp($this->staging, $this->server, 'storefront');
    $bare = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'Bare']);
    $bare->environments()->delete();

    $project = projectCanvasSession($this, $this->admin, $this->team)
        ->getJson("/canvas/api/projects/{$this->project->uuid}/canvas")
        ->assertOk();
    $placeholder = collect($project->json('nodes'))->firstWhere('type', 'empty');

    // Next to the other environments: on the server of the project's resources.
    expect(collect($project->json('zones'))->pluck('name')->all())->toBe(['web-server'])
        ->and($placeholder)->toMatchArray([
            'id' => "empty:{$this->production->uuid}",
            'zone' => $this->server->uuid,
            'context' => 'production',
            // Admins add the first resource on the deploy canvas, with the environment chosen.
            'link' => route('deploy-canvas', ['project' => $this->project->uuid, 'environment' => $this->production->uuid]),
            'context_link' => route('project.resource.index', ['project_uuid' => $this->project->uuid, 'environment_uuid' => $this->production->uuid]),
            'environment' => ['uuid' => $this->production->uuid, 'name' => 'production'],
        ]);

    // Members cannot add resources: the card opens the environment page.
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);
    $memberView = projectCanvasSession($this, $member, $this->team)
        ->getJson("/canvas/api/projects/{$this->project->uuid}/canvas")
        ->assertOk();
    expect(collect($memberView->json('nodes'))->firstWhere('type', 'empty')['link'])
        ->toBe(route('project.resource.index', ['project_uuid' => $this->project->uuid, 'environment_uuid' => $this->production->uuid]));

    // A project without resources has no server: its environments get their own zone.
    $alone = projectCanvasSession($this, $this->admin, $this->team)
        ->getJson("/canvas/api/projects/{$bare->uuid}/canvas")
        ->assertOk();
    expect(collect($alone->json('zones'))->pluck('name')->all())->toBe(['Empty environments'])
        ->and($alone->json('nodes.0.name'))->toBe('No environments yet');

    $team = projectCanvasSession($this, $this->admin, $this->team)
        ->getJson('/canvas/api/projects/canvas')
        ->assertOk();

    // A project without environments gets one placeholder in its zone.
    expect(collect($team->json('nodes'))->firstWhere('zone', $bare->uuid))->toMatchArray([
        'type' => 'empty',
        'name' => 'No environments yet',
        'link' => route('project.show', ['project_uuid' => $bare->uuid]),
        'environment' => null,
    ]);
});

test('the projects page reopens the canvas when the user left it on the canvas view', function () {
    projectCanvasSession($this, $this->admin, $this->team)
        ->get(route('project.canvas.index'))
        ->assertOk()
        ->assertCookie("coolify_{$this->admin->id}_projects_view", 'canvas');

    projectCanvasSession($this, $this->admin, $this->team)
        ->withCookie("coolify_{$this->admin->id}_projects_view", 'canvas')
        ->get(route('project.index'))
        ->assertRedirect(route('project.canvas.index'));

    projectCanvasSession($this, $this->admin, $this->team)
        ->withCookie("coolify_{$this->admin->id}_projects_view", 'canvas')
        ->get(route('project.index', ['view' => 'table']))
        ->assertOk()
        ->assertCookie("coolify_{$this->admin->id}_projects_view", 'table');
});

test('an admin creates an environment from the project canvas', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    Livewire::test(ProjectCanvas::class, ['project_uuid' => $this->project->uuid])
        ->set('name', 'testing')
        ->call('submit')
        ->assertRedirect();

    expect($this->project->environments()->where('name', 'testing')->exists())->toBeTrue();
});

test('a member cannot create an environment from the project canvas', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);
    $this->actingAs($member);
    session(['currentTeam' => $this->team]);

    Livewire::test(ProjectCanvas::class, ['project_uuid' => $this->project->uuid])
        ->set('name', 'testing')
        ->call('submit')
        ->assertDispatched('error');

    expect($this->project->environments()->where('name', 'testing')->exists())->toBeFalse();
});

test('an admin moves project zones on the projects canvas and the canvas returns their place', function () {
    $blog = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'Blog']);
    // Another team's project with the same request stays as it is.
    $foreign = Project::factory()->create(['team_id' => Team::factory()->create()->id]);

    projectCanvasSession($this, $this->admin, $this->team)
        ->getJson('/canvas/api/projects/canvas')
        ->assertJsonPath('can_move_zones', true)
        ->assertJsonPath('zones.0.position', null);

    projectCanvasSession($this, $this->admin, $this->team)
        ->patchJson('/canvas/api/projects/canvas/positions', ['positions' => [
            $this->project->uuid => ['x' => 640, 'y' => -120],
            $blog->uuid => ['x' => 0, 'y' => 0],
            $foreign->uuid => ['x' => 5, 'y' => 5],
        ]])
        ->assertOk()
        ->assertJsonPath("positions.{$this->project->uuid}", ['x' => 640, 'y' => -120])
        ->assertJsonMissingPath("positions.{$foreign->uuid}");

    expect($foreign->fresh()->canvas_position)->toBeNull();
    // Zones are sorted by name: Blog, Shop.
    projectCanvasSession($this, $this->admin, $this->team)
        ->getJson('/canvas/api/projects/canvas')
        ->assertJsonPath('zones.0.position', ['x' => 0, 'y' => 0])
        ->assertJsonPath('zones.1.position', ['x' => 640, 'y' => -120]);
});

test('project zone positions must be whole coordinates on the canvas', function () {
    projectCanvasSession($this, $this->admin, $this->team)
        ->patchJson('/canvas/api/projects/canvas/positions', ['positions' => [$this->project->uuid => ['x' => 'left', 'y' => 999999999]]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(["positions.{$this->project->uuid}.x", "positions.{$this->project->uuid}.y"]);

    expect($this->project->fresh()->canvas_position)->toBeNull();
});

test('members and other teams cannot move a project zone', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);
    projectCanvasSession($this, $member, $this->team)
        ->getJson('/canvas/api/projects/canvas')
        ->assertJsonPath('can_move_zones', false);
    projectCanvasSession($this, $member, $this->team)
        ->patchJson('/canvas/api/projects/canvas/positions', ['positions' => [$this->project->uuid => ['x' => 10, 'y' => 10]]])
        ->assertForbidden();

    // Admin of this team, owner of another team, with the session switched to the other team.
    $otherTeam = Team::factory()->create(['show_boarding' => false]);
    $otherTeam->members()->attach($this->admin->id, ['role' => 'owner']);
    projectCanvasSession($this, $this->admin, $otherTeam)
        ->patchJson('/canvas/api/projects/canvas/positions', ['positions' => [$this->project->uuid => ['x' => 10, 'y' => 10]]])
        ->assertOk()
        ->assertJsonPath('positions', []);

    expect($this->project->fresh()->canvas_position)->toBeNull();
});
