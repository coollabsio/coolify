<?php

use App\Livewire\Server\Index as ServerIndex;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\ResourceLink;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Services\ServerCanvas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cookie;
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

    $this->web = Server::factory()->create(['team_id' => $this->team->id, 'name' => 'web-server', 'ip' => '203.0.113.20']);
    $this->db = Server::factory()->create(['team_id' => $this->team->id, 'name' => 'db-server']);
    $this->project = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'Shop']);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id, 'name' => 'staging']);
});

function serverCanvasApp(Environment $environment, Server $server, string $name): Application
{
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();

    return Application::factory()->create([
        'name' => $name,
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
}

function serverCanvasSession(object $test, User $user, Team $team): object
{
    return $test->actingAs($user)->withSession(['currentTeam' => $team])
        ->withHeader('X-Canvas-Team', (string) $team->id);
}

test('the servers canvas shows every server of the team with its resources and links', function () {
    $app = serverCanvasApp($this->environment, $this->web, 'storefront');
    $api = serverCanvasApp($this->environment, $this->db, 'orders-api');
    $link = ResourceLink::create([
        'environment_id' => $this->environment->id,
        'source_type' => $app->getMorphClass(), 'source_id' => $app->id,
        'target_type' => $api->getMorphClass(), 'target_id' => $api->id,
    ]);

    $response = serverCanvasSession($this, $this->admin, $this->team)
        ->getJson('/canvas/api/servers/canvas')
        ->assertOk()
        ->assertJsonPath('title', 'Servers')
        ->assertJsonPath('title_link', null)
        ->assertJsonPath('can_edit', true)
        ->assertJsonPath('layout', 'server')
        ->assertJsonPath('positions', [])
        ->assertJsonPath('links.0.id', $link->uuid);

    $resources = collect($response->json('nodes'))->where('type', '!=', 'empty');

    // The project's empty production environment shows as a placeholder card on the server of its other environments.
    expect(collect($response->json('zones'))->pluck('name')->all())->toBe(['db-server', 'web-server'])
        ->and(collect($response->json('nodes'))->mapWithKeys(fn ($node) => [$node['name'] => [$node['zone'], $node['context']]])->all())->toBe([
            'storefront' => [$this->web->uuid, 'Shop / staging'],
            'orders-api' => [$this->db->uuid, 'Shop / staging'],
            'No resources yet' => [$this->web->uuid, 'Shop / production'],
        ])
        ->and($response->json('nodes.0.project'))->toBe(['uuid' => $this->project->uuid, 'name' => 'Shop'])
        ->and($response->json('nodes.0.environment'))->toBe(['uuid' => $this->environment->uuid, 'name' => 'staging'])
        ->and($resources->pluck('context_link')->unique()->all())->toBe([
            route('project.resource.index', ['project_uuid' => $this->project->uuid, 'environment_uuid' => $this->environment->uuid]),
        ])
        ->and(collect($response->json('zones'))->pluck('link')->all())->toBe([
            route('server.show', ['server_uuid' => $this->db->uuid]),
            route('server.show', ['server_uuid' => $this->web->uuid]),
        ]);
});

test('the servers canvas puts empty environments of a project without resources on the first server', function () {
    $bare = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'Bare']);

    $response = serverCanvasSession($this, $this->admin, $this->team)
        ->getJson('/canvas/api/servers/canvas')
        ->assertOk();

    expect(collect($response->json('zones'))->pluck('name')->all())->toBe(['db-server', 'web-server'])
        ->and(collect($response->json('nodes'))->where('project.uuid', $bare->uuid)->pluck('zone')->all())->toBe([$this->db->uuid]);
});

test('the servers canvas shows a server without resources as a placeholder card in its zone', function () {
    serverCanvasApp($this->environment, $this->web, 'storefront');
    Environment::query()->where('project_id', $this->project->id)->where('name', '!=', 'staging')->delete();

    $response = serverCanvasSession($this, $this->admin, $this->team)
        ->getJson('/canvas/api/servers/canvas')
        ->assertOk();

    $placeholder = collect($response->json('nodes'))->firstWhere('zone', $this->db->uuid);

    expect($placeholder)->toMatchArray([
        'id' => 'empty-server:'.$this->db->uuid,
        'type' => 'empty',
        'name' => 'No resources yet',
        'link' => route('server.show', ['server_uuid' => $this->db->uuid]),
    ])->and(collect($response->json('nodes'))->where('zone', $this->web->uuid)->pluck('name')->all())->toBe(['storefront']);
});

test('one server canvas shows only that server, and links that leave it are hidden', function () {
    $app = serverCanvasApp($this->environment, $this->web, 'storefront');
    $api = serverCanvasApp($this->environment, $this->db, 'orders-api');
    ResourceLink::create([
        'environment_id' => $this->environment->id,
        'source_type' => $app->getMorphClass(), 'source_id' => $app->id,
        'target_type' => $api->getMorphClass(), 'target_id' => $api->id,
    ]);

    serverCanvasSession($this, $this->admin, $this->team)
        ->getJson("/canvas/api/servers/{$this->web->uuid}/canvas")
        ->assertOk()
        ->assertJsonPath('title', 'web-server')
        ->assertJsonPath('title_link', route('server.show', ['server_uuid' => $this->web->uuid]))
        ->assertJsonCount(1, 'zones')
        ->assertJsonPath('nodes.0.name', 'storefront')
        ->assertJsonCount(1, 'nodes')
        ->assertJsonPath('links', []);
});

test('the server canvas sends the card positions saved on the environment canvas with each card', function () {
    $app = serverCanvasApp($this->environment, $this->web, 'storefront');
    $other = serverCanvasApp($this->environment, $this->web, 'checkout');
    $this->environment->forceFill(['canvas_positions' => [$app->uuid => ['x' => 120, 'y' => 40]]])->save();

    $nodes = collect(serverCanvasSession($this, $this->admin, $this->team)
        ->getJson('/canvas/api/servers/canvas')
        ->assertOk()
        ->assertJsonPath('can_edit', true)
        ->assertJsonPath('can_move', false)
        ->assertJsonPath('positions', [])
        ->json('nodes'))->keyBy('id');

    expect($nodes[$app->uuid]['saved'])->toBe(['x' => 120, 'y' => 40])
        ->and($nodes[$other->uuid]['saved'])->toBeNull();

    serverCanvasSession($this, $this->admin, $this->team)
        ->getJson("/canvas/api/servers/{$this->web->uuid}/canvas")
        ->assertOk()
        ->assertJsonPath('can_move', false)
        ->assertJsonPath('positions', []);
});

test('members see the server canvas without server IPs and cannot edit it', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);
    serverCanvasApp($this->environment, $this->web, 'storefront');

    serverCanvasSession($this, $member, $this->team)
        ->getJson("/canvas/api/servers/{$this->web->uuid}/canvas")
        ->assertOk()
        ->assertJsonPath('can_edit', false)
        ->assertJsonPath('zones.0.ip', null);

    serverCanvasSession($this, $this->admin, $this->team)
        ->getJson("/canvas/api/servers/{$this->web->uuid}/canvas")
        ->assertJsonPath('zones.0.ip', '203.0.113.20');
});

test('the server canvas never shows another team servers or resources', function () {
    $otherTeam = Team::factory()->create(['show_boarding' => false]);
    $outsider = User::factory()->create();
    $otherTeam->members()->attach($outsider->id, ['role' => 'owner']);
    $this->team->members()->attach($outsider->id, ['role' => 'member']);
    serverCanvasApp($this->environment, $this->web, 'storefront');

    // Session switched to the other team: this team's server is out of reach.
    serverCanvasSession($this, $outsider, $otherTeam)
        ->getJson("/canvas/api/servers/{$this->web->uuid}/canvas")
        ->assertNotFound();
    $all = serverCanvasSession($this, $outsider, $otherTeam)
        ->getJson('/canvas/api/servers/canvas')
        ->assertOk();
    expect($all->json('zones'))->toBe([])->and($all->json('nodes'))->toBe([]);

    serverCanvasSession($this, $outsider, $otherTeam)
        ->get(route('server.canvas', ['server_uuid' => $this->web->uuid]))
        ->assertNotFound();
});

test('the canvas pages open for team members', function () {
    serverCanvasSession($this, $this->admin, $this->team)
        ->withoutVite()
        ->get(route('server.canvas.index'))
        ->assertOk()
        ->assertSee('data-endpoint="/servers/canvas"', false);

    serverCanvasSession($this, $this->admin, $this->team)
        ->withoutVite()
        ->get(route('server.canvas', ['server_uuid' => $this->web->uuid]))
        ->assertOk()
        ->assertSee('data-endpoint="/servers/'.$this->web->uuid.'/canvas"', false);
});

test('the servers page reopens the canvas when the user left it on the canvas view', function () {
    serverCanvasSession($this, $this->admin, $this->team)
        ->get(route('server.canvas.index'))
        ->assertOk()
        ->assertCookie("coolify_{$this->admin->id}_servers_view", 'canvas');

    serverCanvasSession($this, $this->admin, $this->team)
        ->withCookie("coolify_{$this->admin->id}_servers_view", 'canvas')
        ->get(route('server.index'))
        ->assertRedirect(route('server.canvas.index'));

    // The table / grid links on the canvas pick the list view again.
    serverCanvasSession($this, $this->admin, $this->team)
        ->withCookie("coolify_{$this->admin->id}_servers_view", 'canvas')
        ->get(route('server.index', ['view' => 'grid']))
        ->assertOk()
        ->assertCookie("coolify_{$this->admin->id}_servers_view", 'grid');

    serverCanvasSession($this, $this->admin, $this->team)
        ->withCookie("coolify_{$this->admin->id}_servers_view", 'grid')
        ->get(route('server.index'))
        ->assertOk();
});

test('the environment page reopens the canvas when the user left it on the canvas view', function () {
    $parameters = ['project_uuid' => $this->project->uuid, 'environment_uuid' => $this->environment->uuid];

    serverCanvasSession($this, $this->admin, $this->team)
        ->get(route('project.environment.canvas', $parameters))
        ->assertOk()
        ->assertCookie("coolify_{$this->admin->id}_environment_view", 'canvas');

    serverCanvasSession($this, $this->admin, $this->team)
        ->withCookie("coolify_{$this->admin->id}_environment_view", 'canvas')
        ->get(route('project.resource.index', $parameters))
        ->assertRedirect(route('project.environment.canvas', $parameters));

    serverCanvasSession($this, $this->admin, $this->team)
        ->withCookie("coolify_{$this->admin->id}_environment_view", 'canvas')
        ->get(route('project.resource.index', [...$parameters, 'view' => 'table']))
        ->assertOk()
        ->assertCookie("coolify_{$this->admin->id}_environment_view", 'table');
});

test('the server resources page reopens the one server canvas when the user left it on the canvas view', function () {
    $parameters = ['server_uuid' => $this->web->uuid];

    serverCanvasSession($this, $this->admin, $this->team)
        ->get(route('server.canvas', $parameters))
        ->assertOk()
        ->assertCookie("coolify_{$this->admin->id}_server_resources_view", 'canvas');

    serverCanvasSession($this, $this->admin, $this->team)
        ->withCookie("coolify_{$this->admin->id}_server_resources_view", 'canvas')
        ->get(route('server.resources', $parameters))
        ->assertRedirect(route('server.canvas', $parameters));

    serverCanvasSession($this, $this->admin, $this->team)
        ->withCookie("coolify_{$this->admin->id}_server_resources_view", 'canvas')
        ->get(route('server.resources', [...$parameters, 'view' => 'list']))
        ->assertOk()
        ->assertCookie("coolify_{$this->admin->id}_server_resources_view", 'list');
});

test('the canvas API works with the browser session when the instance API is disabled', function () {
    InstanceSettings::query()->whereKey(0)->update(['is_api_enabled' => false]);

    serverCanvasSession($this, $this->admin, $this->team)
        ->getJson('/canvas/api/servers/canvas')
        ->assertOk()
        ->assertJsonPath('title', 'Servers');
});

test('the remembered view belongs to the user, not to the browser', function () {
    $other = User::factory()->create();
    $this->team->members()->attach($other->id, ['role' => 'admin']);

    serverCanvasSession($this, $other, $this->team)
        ->withCookie("coolify_{$this->admin->id}_servers_view", 'canvas')
        ->get(route('server.index'))
        ->assertOk();
});

test('the list page remembers the table or grid choice and opens it again', function () {
    $this->actingAs($this->admin)->withSession(['currentTeam' => $this->team]);
    $cookie = "coolify_{$this->admin->id}_servers_view";

    Livewire::test(ServerIndex::class)->call('saveListView', 'servers', 'grid');
    expect(Cookie::queued($cookie)?->getValue())->toBe('grid');

    // Unknown pages and views are ignored.
    Cookie::flushQueuedCookies();
    Livewire::test(ServerIndex::class)
        ->call('saveListView', 'servers', 'canvas')
        ->call('saveListView', 'billing', 'grid');
    expect(Cookie::getQueuedCookies())->toBeEmpty();

    serverCanvasSession($this, $this->admin, $this->team)
        ->withCookie($cookie, 'grid')
        ->get(route('server.index'))
        ->assertOk()
        ->assertSee("viewMode: 'grid'", false);
});

test('one server canvas loads the unmanaged containers of that server', function () {
    $server = Mockery::mock($this->web)->makePartial();
    $server->shouldReceive('loadUnmanagedContainers')->andReturn(collect([
        ['ID' => 'abc123', 'Names' => 'portainer', 'Image' => 'portainer/portainer-ce', 'State' => 'running', 'Status' => 'Up 2 hours (healthy)'],
        ['ID' => 'def456', 'Names' => 'old-job', 'Image' => 'busybox', 'State' => 'exited', 'Status' => 'Exited (0) 3 days ago'],
    ]));

    $nodes = app(ServerCanvas::class)->unmanagedContainerNodes($server);

    expect($nodes->map(fn ($node) => [$node['id'], $node['type'], $node['name'], $node['status'], $node['subtitle'], $node['zone'], $node['context']])->all())->toBe([
        ['container:def456', 'container', 'old-job', 'exited', 'busybox', $this->web->uuid, 'Unmanaged containers'],
        ['container:abc123', 'container', 'portainer', 'running:healthy', 'portainer/portainer-ce', $this->web->uuid, 'Unmanaged containers'],
    ]);
});

test('the server canvas still loads when the unmanaged containers cannot be read', function () {
    $server = Mockery::mock($this->web)->makePartial();
    $server->shouldReceive('loadUnmanagedContainers')->andThrow(new RuntimeException('SSH failed'));

    expect(app(ServerCanvas::class)->unmanagedContainerNodes($server)->all())->toBe([]);
});

test('only the one server canvas points to the unmanaged containers of its server', function () {
    $otherTeam = Team::factory()->create(['show_boarding' => false]);
    $foreign = Server::factory()->create(['team_id' => $otherTeam->id]);

    serverCanvasSession($this, $this->admin, $this->team)->getJson("/canvas/api/servers/{$this->web->uuid}/canvas")
        ->assertJsonPath('containers_endpoint', "/servers/{$this->web->uuid}/canvas/containers");
    serverCanvasSession($this, $this->admin, $this->team)->getJson('/canvas/api/servers/canvas')
        ->assertJsonPath('containers_endpoint', null);
    serverCanvasSession($this, $this->admin, $this->team)->getJson("/canvas/api/servers/{$this->web->uuid}/canvas/containers")
        ->assertOk()->assertExactJson(['nodes' => []]);
    serverCanvasSession($this, $this->admin, $this->team)->getJson("/canvas/api/servers/{$foreign->uuid}/canvas/containers")
        ->assertNotFound();
});

test('server zones show host facts, the proxy and a usage endpoint only when metrics can be read', function () {
    $this->web->forceFill([
        'server_metadata' => ['os' => 'Ubuntu 24.04', 'arch' => 'x86_64', 'cpus' => 4, 'memory_bytes' => 8589934592, 'uptime_since' => '2026-10-01 10:00:00'],
        'proxy' => ['type' => 'TRAEFIK', 'status' => 'running'],
    ])->save();
    $this->web->settings->forceFill(['is_reachable' => true, 'is_metrics_enabled' => true])->saveQuietly();
    $this->db->settings->forceFill(['is_reachable' => true, 'is_metrics_enabled' => false])->saveQuietly();
    $this->db->forceFill(['proxy' => ['type' => 'NONE']])->save();

    $zones = collect(serverCanvasSession($this, $this->admin, $this->team)
        ->getJson('/canvas/api/servers/canvas')
        ->assertOk()
        ->json('zones'))->keyBy('name');

    expect($zones['web-server']['details'])->toBe([
        'os' => 'Ubuntu 24.04',
        'arch' => 'x86_64',
        'cpus' => 4,
        'memory_bytes' => 8589934592,
        'uptime_since' => '2026-10-01 10:00:00',
        'proxy' => ['type' => 'TRAEFIK', 'status' => 'running'],
        'usage_endpoint' => "/servers/{$this->web->uuid}/canvas/usage",
    ])
        ->and($zones['db-server']['details']['proxy'])->toBeNull()
        ->and($zones['db-server']['details']['usage_endpoint'])->toBeNull();
});

test('the usage endpoint returns the latest Sentinel values and never another team server', function () {
    $server = Mockery::mock($this->web)->makePartial();
    $server->shouldReceive('prefetchMetrics')->once()->with(['cpu', 'memory', 'disk'], 1);
    $server->shouldReceive('getCpuMetrics')->andReturn([[1, 10.0], [2, 12.34]]);
    $server->shouldReceive('getMemoryMetrics')->andReturn([[2, 40.0]]);
    $server->shouldReceive('getDiskMetrics')->andThrow(new RuntimeException('no disk data'));

    expect(app(ServerCanvas::class)->usage($server))->toBe(['cpu' => 12.3, 'memory' => 40.0, 'disk' => null]);

    $this->web->settings->forceFill(['is_metrics_enabled' => false])->saveQuietly();
    serverCanvasSession($this, $this->admin, $this->team)->getJson("/canvas/api/servers/{$this->web->uuid}/canvas/usage")
        ->assertOk()->assertExactJson(['cpu' => null, 'memory' => null, 'disk' => null]);

    $otherTeam = Team::factory()->create(['show_boarding' => false]);
    $foreign = Server::factory()->create(['team_id' => $otherTeam->id]);
    serverCanvasSession($this, $this->admin, $this->team)->getJson("/canvas/api/servers/{$foreign->uuid}/canvas/usage")
        ->assertNotFound();
});

test('only admins may move the environment groups on the server canvas', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);

    serverCanvasSession($this, $this->admin, $this->team)->getJson('/canvas/api/servers/canvas')
        ->assertJsonPath('can_move_groups', true);
    serverCanvasSession($this, $member, $this->team)->getJson('/canvas/api/servers/canvas')
        ->assertJsonPath('can_move_groups', false);
});

test('an admin moves server zones on the servers and dashboard canvases and both return their place', function () {
    // Another team's server with the same request stays as it is.
    $foreign = Server::factory()->create(['team_id' => Team::factory()->create()->id]);

    serverCanvasSession($this, $this->admin, $this->team)->getJson('/canvas/api/servers/canvas')
        ->assertJsonPath('can_move_zones', true)
        ->assertJsonPath('zones.0.position', null);

    serverCanvasSession($this, $this->admin, $this->team)
        ->patchJson('/canvas/api/dashboard/canvas/positions', ['positions' => [
            $this->web->uuid => ['x' => 640, 'y' => -120],
            $foreign->uuid => ['x' => 5, 'y' => 5],
            'no-server' => ['x' => 0, 'y' => 0],
        ]])
        ->assertOk()
        ->assertJsonPath('positions', [$this->web->uuid => ['x' => 640, 'y' => -120]]);
    serverCanvasSession($this, $this->admin, $this->team)
        ->patchJson('/canvas/api/servers/canvas/positions', ['positions' => [$this->db->uuid => ['x' => 0, 'y' => 0]]])
        ->assertOk();

    expect($foreign->fresh()->canvas_position)->toBeNull();
    // Zones are sorted by name: db-server, web-server.
    foreach (['/canvas/api/servers/canvas', '/canvas/api/dashboard/canvas'] as $endpoint) {
        serverCanvasSession($this, $this->admin, $this->team)->getJson($endpoint)
            ->assertJsonPath('zones.0.position', ['x' => 0, 'y' => 0])
            ->assertJsonPath('zones.1.position', ['x' => 640, 'y' => -120]);
    }
    // The one server canvas has a single zone, which stays in its place.
    serverCanvasSession($this, $this->admin, $this->team)->getJson("/canvas/api/servers/{$this->web->uuid}/canvas")
        ->assertJsonPath('can_move_zones', false)
        ->assertJsonPath('zones.0.position', null);
});

test('server zone positions must be whole coordinates on the canvas', function () {
    serverCanvasSession($this, $this->admin, $this->team)
        ->patchJson('/canvas/api/servers/canvas/positions', ['positions' => [$this->web->uuid => ['x' => 'left', 'y' => 999999999]]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(["positions.{$this->web->uuid}.x", "positions.{$this->web->uuid}.y"]);

    expect($this->web->fresh()->canvas_position)->toBeNull();
});

test('members and other teams cannot move a server zone', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);
    serverCanvasSession($this, $member, $this->team)->getJson('/canvas/api/dashboard/canvas')
        ->assertJsonPath('can_move_zones', false);
    serverCanvasSession($this, $member, $this->team)
        ->patchJson('/canvas/api/servers/canvas/positions', ['positions' => [$this->web->uuid => ['x' => 10, 'y' => 10]]])
        ->assertForbidden();

    // Admin of this team, owner of another team, with the session switched to the other team.
    $otherTeam = Team::factory()->create(['show_boarding' => false]);
    $otherTeam->members()->attach($this->admin->id, ['role' => 'owner']);
    serverCanvasSession($this, $this->admin, $otherTeam)
        ->patchJson('/canvas/api/dashboard/canvas/positions', ['positions' => [$this->web->uuid => ['x' => 10, 'y' => 10]]])
        ->assertOk()
        ->assertJsonPath('positions', []);

    expect($this->web->fresh()->canvas_position)->toBeNull();
});
