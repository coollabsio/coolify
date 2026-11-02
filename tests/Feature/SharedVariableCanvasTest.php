<?php

use App\Livewire\SharedVariables\Canvas as SharedVariablesCanvas;
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
use Illuminate\Support\Arr;
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
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id, 'name' => 'staging']);
    $this->storefront = sharedVariableCanvasApp($this->environment, $this->server, 'storefront');
    $this->token = SharedEnvironmentVariable::create([
        'key' => 'API_TOKEN', 'value' => 'secret-value', 'type' => 'team', 'team_id' => $this->team->id,
    ]);
});

function sharedVariableCanvasApp(Environment $environment, Server $server, string $name): Application
{
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();

    return Application::factory()->create([
        'name' => $name,
        'build_pack' => 'dockerimage',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
}

function sharedVariableCanvasEnv(Application $application, string $key, string $value): EnvironmentVariable
{
    return EnvironmentVariable::create([
        'key' => $key, 'value' => $value,
        'resourceable_type' => $application->getMorphClass(), 'resourceable_id' => $application->id,
    ]);
}

function sharedVariableCanvasEnvs(Application $application)
{
    return EnvironmentVariable::query()->where('resourceable_type', $application->getMorphClass())->where('resourceable_id', $application->id);
}

function sharedVariableCanvasAs(object $test, User $user, Team $team): object
{
    return $test->actingAs($user)->withSession(['currentTeam' => $team])
        ->withHeader('X-Canvas-Team', (string) $team->id);
}

test('the team canvas shows the variables, the resources and which resources use them, without values', function () {
    sharedVariableCanvasEnv($this->storefront, 'API_TOKEN', '{{team.API_TOKEN}}');
    $worker = sharedVariableCanvasApp($this->environment, $this->server, 'worker');
    sharedVariableCanvasEnv($worker, 'DATABASE_URL', 'postgres://{{ team.API_TOKEN }}@db');
    sharedVariableCanvasEnv($worker, 'OLD', '{{team.REMOVED_KEY}}');
    sharedVariableCanvasEnv($worker, 'PROJECT_ONLY', '{{project.API_TOKEN}}');

    $response = sharedVariableCanvasAs($this, $this->admin, $this->team)
        ->getJson('/canvas/api/shared-variables/team/canvas')
        ->assertOk()
        ->assertJsonPath('layout', 'connect')
        ->assertJsonPath('can_edit', true)
        ->assertJsonPath('title_link', route('shared-variables.team.index'));

    $variableId = 'variable:'.$this->token->id;
    expect($response->getContent())->not->toContain('secret-value')
        ->and(collect($response->json('nodes'))->mapWithKeys(fn ($node) => [$node['id'] => [$node['name'], $node['zone']]])->all())->toBe([
            $variableId => ['API_TOKEN', 'sources'],
            'missing:REMOVED_KEY' => ['REMOVED_KEY', 'sources'],
            $this->storefront->uuid => ['storefront', 'resources'],
            $worker->uuid => ['worker', 'resources'],
        ])
        ->and(collect($response->json('links'))->mapWithKeys(fn ($link) => [$link['id'] => Arr::only($link, ['label', 'removable', 'missing'])])->all())->toBe([
            "{$variableId}|{$this->storefront->uuid}" => ['label' => null, 'removable' => true, 'missing' => false],
            "{$variableId}|{$worker->uuid}" => ['label' => 'DATABASE_URL', 'removable' => false, 'missing' => false],
            "missing:REMOVED_KEY|{$worker->uuid}" => ['label' => 'OLD', 'removable' => true, 'missing' => true],
        ]);

    // Resources carry their project and environment for the canvas filter.
    $storefront = collect($response->json('nodes'))->firstWhere('id', $this->storefront->uuid);
    expect($storefront['project'])->toBe(['uuid' => $this->project->uuid, 'name' => 'Shop'])
        ->and($storefront['environment'])->toBe(['uuid' => $this->environment->uuid, 'name' => 'staging']);
});

test('an admin adds a shared variable to a resource from the canvas', function () {
    sharedVariableCanvasAs($this, $this->admin, $this->team)
        ->postJson('/canvas/api/shared-variables/team/canvas/links', [
            'source' => 'variable:'.$this->token->id,
            'target' => $this->storefront->uuid,
        ])
        ->assertCreated()
        ->assertJsonPath('id', 'variable:'.$this->token->id.'|'.$this->storefront->uuid)
        ->assertJsonPath('keys', ['API_TOKEN']);

    $variables = sharedVariableCanvasEnvs($this->storefront)->get();
    expect($variables)->toHaveCount(2)
        ->and($variables->pluck('value')->unique()->all())->toBe(['{{team.API_TOKEN}}'])
        ->and($variables->pluck('is_preview')->sort()->values()->all())->toBe([false, true])
        ->and($variables->firstWhere('is_preview', false)->real_value)->toBe('secret-value');

    // Adding it again, or over a variable with the same name, is refused.
    sharedVariableCanvasAs($this, $this->admin, $this->team)
        ->postJson('/canvas/api/shared-variables/team/canvas/links', ['source' => 'variable:'.$this->token->id, 'target' => $this->storefront->uuid])
        ->assertStatus(422)
        ->assertJsonPath('message', 'storefront already uses API_TOKEN.');
    $other = sharedVariableCanvasApp($this->environment, $this->server, 'other');
    sharedVariableCanvasEnv($other, 'API_TOKEN', 'local-value');
    sharedVariableCanvasAs($this, $this->admin, $this->team)
        ->postJson('/canvas/api/shared-variables/team/canvas/links', ['source' => 'variable:'.$this->token->id, 'target' => $other->uuid])
        ->assertStatus(422)
        ->assertJsonPath('message', 'other already has a variable named API_TOKEN. Rename or remove it first.');
});

test('removing a link deletes the reference and its preview copy, but not a longer value', function () {
    sharedVariableCanvasEnv($this->storefront, 'API_TOKEN', '{{team.API_TOKEN}}');
    $linkId = 'variable:'.$this->token->id.'|'.$this->storefront->uuid;
    expect(sharedVariableCanvasEnvs($this->storefront)->count())->toBe(2);

    sharedVariableCanvasAs($this, $this->admin, $this->team)
        ->deleteJson('/canvas/api/shared-variables/team/canvas/links/'.rawurlencode($linkId))
        ->assertOk();
    expect(sharedVariableCanvasEnvs($this->storefront)->count())->toBe(0);

    sharedVariableCanvasEnv($this->storefront, 'DATABASE_URL', 'postgres://{{team.API_TOKEN}}@db');
    sharedVariableCanvasAs($this, $this->admin, $this->team)
        ->deleteJson('/canvas/api/shared-variables/team/canvas/links/'.rawurlencode($linkId))
        ->assertStatus(422);
    expect(sharedVariableCanvasEnvs($this->storefront)->where('is_preview', false)->pluck('key')->all())->toBe(['DATABASE_URL']);
});

test('a reference to a deleted shared variable can be removed', function () {
    sharedVariableCanvasEnv($this->storefront, 'OLD', '{{team.REMOVED_KEY}}');

    sharedVariableCanvasAs($this, $this->admin, $this->team)
        ->deleteJson('/canvas/api/shared-variables/team/canvas/links/'.rawurlencode('missing:REMOVED_KEY|'.$this->storefront->uuid))
        ->assertOk();

    expect(sharedVariableCanvasEnvs($this->storefront)->exists())->toBeFalse();
});

test('a member sees the canvas but cannot add or remove shared variables', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);
    sharedVariableCanvasEnv($this->storefront, 'API_TOKEN', '{{team.API_TOKEN}}');

    sharedVariableCanvasAs($this, $member, $this->team)
        ->getJson('/canvas/api/shared-variables/team/canvas')
        ->assertOk()
        ->assertJsonPath('can_edit', false);
    sharedVariableCanvasAs($this, $member, $this->team)
        ->postJson('/canvas/api/shared-variables/team/canvas/links', ['source' => 'variable:'.$this->token->id, 'target' => $this->storefront->uuid])
        ->assertForbidden();
    sharedVariableCanvasAs($this, $member, $this->team)
        ->deleteJson('/canvas/api/shared-variables/team/canvas/links/'.rawurlencode('variable:'.$this->token->id.'|'.$this->storefront->uuid))
        ->assertForbidden();

    expect(sharedVariableCanvasEnvs($this->storefront)->count())->toBe(2);
});

test('a project canvas only links project variables to resources of that project', function () {
    $projectVariable = SharedEnvironmentVariable::create([
        'key' => 'SHOP_NAME', 'value' => 'shop', 'type' => 'project', 'team_id' => $this->team->id, 'project_id' => $this->project->id,
    ]);
    $otherProject = Project::factory()->create(['team_id' => $this->team->id]);
    $outside = sharedVariableCanvasApp(Environment::factory()->create(['project_id' => $otherProject->id]), $this->server, 'outside');
    $base = "/canvas/api/shared-variables/project/{$this->project->uuid}/canvas";

    $response = sharedVariableCanvasAs($this, $this->admin, $this->team)->getJson($base)->assertOk();
    expect(collect($response->json('nodes'))->pluck('name')->all())->toBe(['SHOP_NAME', 'storefront']);

    sharedVariableCanvasAs($this, $this->admin, $this->team)
        ->postJson("{$base}/links", ['source' => 'variable:'.$projectVariable->id, 'target' => $outside->uuid])
        ->assertStatus(422)
        ->assertJsonValidationErrors('target');
    sharedVariableCanvasAs($this, $this->admin, $this->team)
        ->postJson("{$base}/links", ['source' => 'variable:'.$this->token->id, 'target' => $this->storefront->uuid])
        ->assertStatus(422)
        ->assertJsonValidationErrors('source');
    sharedVariableCanvasAs($this, $this->admin, $this->team)
        ->postJson("{$base}/links", ['source' => 'variable:'.$projectVariable->id, 'target' => $this->storefront->uuid])
        ->assertCreated();

    expect(sharedVariableCanvasEnvs($this->storefront)->where('is_preview', false)->first()->value)->toBe('{{project.SHOP_NAME}}');
});

test('a server canvas only shows resources on that server', function () {
    $other = Server::factory()->create(['team_id' => $this->team->id]);
    sharedVariableCanvasApp($this->environment, $other, 'elsewhere');

    $response = sharedVariableCanvasAs($this, $this->admin, $this->team)
        ->getJson("/canvas/api/shared-variables/server/{$this->server->uuid}/canvas")
        ->assertOk();

    expect(collect($response->json('nodes'))->where('type', '!=', 'variable')->pluck('name')->all())->toBe(['storefront']);
});

test('the server canvas hides the built-in server variables and the references to them', function () {
    $this->server->environment_variables()->create(['key' => 'REGION', 'value' => 'eu', 'team_id' => $this->team->id]);
    sharedVariableCanvasEnv($this->storefront, 'HOST', '{{server.COOLIFY_SERVER_NAME}}');
    sharedVariableCanvasEnv($this->storefront, 'REGION', '{{server.REGION}}');

    $response = sharedVariableCanvasAs($this, $this->admin, $this->team)
        ->getJson("/canvas/api/shared-variables/server/{$this->server->uuid}/canvas")
        ->assertOk();

    // Coolify creates COOLIFY_SERVER_UUID / COOLIFY_SERVER_NAME on every server; they are not shown, not even as deleted.
    expect(collect($response->json('nodes'))->where('type', 'variable')->pluck('name')->all())->toBe(['REGION'])
        ->and(collect($response->json('links'))->pluck('key')->all())->toBe(['REGION']);
});

test('another team cannot open or change the canvas of a scope it does not own', function () {
    $otherTeam = Team::factory()->create(['show_boarding' => false]);
    $otherTeam->members()->attach($this->admin->id, ['role' => 'owner']);

    // The session is switched to the other team after the canvas was opened.
    sharedVariableCanvasAs($this, $this->admin, $otherTeam)
        ->getJson("/canvas/api/shared-variables/project/{$this->project->uuid}/canvas")
        ->assertNotFound();
    sharedVariableCanvasAs($this, $this->admin, $otherTeam)
        ->postJson('/canvas/api/shared-variables/team/canvas/links', ['source' => 'variable:'.$this->token->id, 'target' => $this->storefront->uuid])
        ->assertStatus(422);
    sharedVariableCanvasAs($this, $this->admin, $otherTeam)
        ->get(route('shared-variables.project.canvas', ['project_uuid' => $this->project->uuid]))
        ->assertNotFound();

    expect(sharedVariableCanvasEnvs($this->storefront)->exists())->toBeFalse();
});

test('the canvas page opens for every scope', function () {
    $routes = [
        route('shared-variables.team.canvas'),
        route('shared-variables.project.canvas', ['project_uuid' => $this->project->uuid]),
        route('shared-variables.environment.canvas', ['project_uuid' => $this->project->uuid, 'environment_uuid' => $this->environment->uuid]),
        route('shared-variables.server.canvas', ['server_uuid' => $this->server->uuid]),
    ];
    $endpoints = [
        '/shared-variables/team/canvas',
        "/shared-variables/project/{$this->project->uuid}/canvas",
        "/shared-variables/environment/{$this->environment->uuid}/canvas",
        "/shared-variables/server/{$this->server->uuid}/canvas",
    ];

    foreach ($routes as $i => $url) {
        sharedVariableCanvasAs($this, $this->admin, $this->team)->get($url)
            ->assertOk()
            ->assertSee('data-endpoint="'.$endpoints[$i].'"', false);
    }
});

test('every shared variable row links to the connect envs canvas with the variable selected', function () {
    $projectVariable = SharedEnvironmentVariable::create([
        'key' => 'SHOP_NAME', 'value' => 'shop', 'type' => 'project', 'team_id' => $this->team->id, 'project_id' => $this->project->id,
    ]);

    sharedVariableCanvasAs($this, $this->admin, $this->team)->get(route('shared-variables.team.index'))
        ->assertOk()
        ->assertSee(route('shared-variables.team.canvas').'?select=variable%3A'.$this->token->id, false);
    sharedVariableCanvasAs($this, $this->admin, $this->team)->get(route('shared-variables.project.show', ['project_uuid' => $this->project->uuid]))
        ->assertOk()
        ->assertSee(route('shared-variables.project.canvas', ['project_uuid' => $this->project->uuid]).'?select=variable%3A'.$projectVariable->id, false);
});

test('each shared variables page reopens its canvas when the user left it on the canvas view', function (string $scope) {
    $parameters = match ($scope) {
        'team' => [],
        'project' => ['project_uuid' => $this->project->uuid],
        'environment' => ['project_uuid' => $this->project->uuid, 'environment_uuid' => $this->environment->uuid],
        'server' => ['server_uuid' => $this->server->uuid],
    };
    $listRoute = $scope === 'team' ? 'shared-variables.team.index' : "shared-variables.{$scope}.show";
    $cookie = "coolify_{$this->admin->id}_{$scope}_variables_view";

    sharedVariableCanvasAs($this, $this->admin, $this->team)
        ->get(route("shared-variables.{$scope}.canvas", $parameters))
        ->assertOk()
        ->assertCookie($cookie, 'canvas');

    sharedVariableCanvasAs($this, $this->admin, $this->team)
        ->withCookie($cookie, 'canvas')
        ->get(route($listRoute, $parameters))
        ->assertRedirect(route("shared-variables.{$scope}.canvas", $parameters));

    sharedVariableCanvasAs($this, $this->admin, $this->team)
        ->withCookie($cookie, 'canvas')
        ->get(route($listRoute, [...$parameters, 'view' => 'list']))
        ->assertOk()
        ->assertCookie($cookie, 'list');
})->with(['team', 'project', 'environment', 'server']);

test('an admin adds a shared variable from the canvas modal and the canvas reloads', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    Livewire::test(SharedVariablesCanvas::class, ['project_uuid' => $this->project->uuid])
        ->dispatch('saveKey', ['key' => 'SHOP_URL', 'value' => 'https://shop.test', 'is_multiline' => false, 'is_literal' => false])
        ->assertRedirect(route('shared-variables.project.canvas', ['project_uuid' => $this->project->uuid]));

    $variable = SharedEnvironmentVariable::query()->where('key', 'SHOP_URL')->firstOrFail();
    expect($variable->type)->toBe('project')
        ->and($variable->project_id)->toBe($this->project->id)
        ->and($variable->team_id)->toBe($this->team->id);
});

test('a member cannot add a shared variable from the canvas, also after switching to an owned team', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);
    $ownTeam = Team::factory()->create();
    $member->teams()->attach($ownTeam, ['role' => 'owner']);
    $this->actingAs($member);
    session(['currentTeam' => $this->team]);

    $component = Livewire::test(SharedVariablesCanvas::class);
    $member->load('teams');
    session(['currentTeam' => $ownTeam]);

    $component
        ->dispatch('saveKey', ['key' => 'SHOP_URL', 'value' => 'x', 'is_multiline' => false, 'is_literal' => false])
        ->assertDispatched('error');

    expect(SharedEnvironmentVariable::query()->where('key', 'SHOP_URL')->exists())->toBeFalse();
});

test('the server canvas rejects the predefined server variables', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    Livewire::test(SharedVariablesCanvas::class, ['server_uuid' => $this->server->uuid])
        ->dispatch('saveKey', ['key' => 'COOLIFY_SERVER_NAME', 'value' => 'x', 'is_multiline' => false, 'is_literal' => false])
        ->assertDispatched('error');

    expect($this->server->environment_variables()->where('key', 'COOLIFY_SERVER_NAME')->where('value', 'x')->exists())->toBeFalse();
});
