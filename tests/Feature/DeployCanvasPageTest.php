<?php

use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    $this->team = Team::factory()->create(['show_boarding' => false]);
    $this->otherTeam = Team::factory()->create(['show_boarding' => false]);
    $this->project = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'canvas-team-project']);
    $this->otherProject = Project::factory()->create(['team_id' => $this->otherTeam->id, 'name' => 'canvas-other-project']);
    $this->user = User::factory()->create();
});

function actingInCanvasTeam(object $test, Team $team): object
{
    return $test->actingAs($test->user)->withSession(['currentTeam' => $team])
        ->withHeader('X-Canvas-Team', (string) $team->id);
}

test('guests are sent to the login page', function () {
    $this->get('/canvas')->assertRedirect('/login');
});

test('team members can open the deploy canvas', function () {
    $this->team->members()->attach($this->user->id, ['role' => 'member']);

    actingInCanvasTeam($this, $this->team)
        ->withoutVite()
        ->get('/canvas')
        ->assertOk()
        ->assertSee('data-deploy-canvas', false);
});

test('the deploy canvas page lists the service template logos for the service step', function () {
    $this->team->members()->attach($this->user->id, ['role' => 'member']);
    $template = get_service_templates()->first(fn ($template) => filled(data_get($template, 'logo')) && basename(data_get($template, 'logo')) !== 'default.webp');
    $key = get_service_templates()->search($template);

    $html = actingInCanvasTeam($this, $this->team)->withoutVite()->get('/canvas')->assertOk()->getContent();

    preg_match('/<script type="application\/json" data-deploy-canvas-service-logos>(.*?)<\/script>/s', $html, $matches);
    $logos = json_decode($matches[1] ?? '', true);
    expect($logos[$key][0])->toBe(service_logo_urls(data_get($template, 'logo'))['logo']);
});

test('guests cannot call the canvas api', function () {
    $this->getJson('/canvas/api/projects')->assertUnauthorized();
});

test('the canvas api works with the session and only shows the session team', function () {
    $this->team->members()->attach($this->user->id, ['role' => 'admin']);

    actingInCanvasTeam($this, $this->team)
        ->getJson('/canvas/api/projects')
        ->assertOk()
        ->assertJsonFragment(['name' => 'canvas-team-project'])
        ->assertJsonMissing(['name' => 'canvas-other-project']);
});

test('the canvas api uses the team of the open page, not the session team', function () {
    // Member of the page's team, owner of the team the session switched to in another tab.
    $this->team->members()->attach($this->user->id, ['role' => 'member']);
    $this->otherTeam->members()->attach($this->user->id, ['role' => 'owner']);
    $page = fn () => $this->actingAs($this->user)->withSession(['currentTeam' => $this->otherTeam])
        ->withHeader('X-Canvas-Team', (string) $this->team->id);

    $page()->getJson('/canvas/api/projects')
        ->assertOk()
        ->assertJsonFragment(['name' => 'canvas-team-project'])
        ->assertJsonMissing(['name' => 'canvas-other-project']);
    // The role in the page's team applies: no owner rights from the session team.
    $page()->postJson('/canvas/api/applications/dockerimage', ['docker_registry_image_name' => 'nginx'])
        ->assertForbidden();
});

test('the canvas api refuses a missing team or a team the user is not a member of', function () {
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->actingAs($this->user)->withSession(['currentTeam' => $this->team])
        ->getJson('/canvas/api/projects')
        ->assertStatus(400);
    $this->actingAs($this->user)->withSession(['currentTeam' => $this->team])
        ->withHeader('X-Canvas-Team', (string) $this->otherTeam->id)
        ->getJson('/canvas/api/projects')
        ->assertUnauthorized();
});

test('the deploy canvas page keeps the team it was opened with', function () {
    $this->team->members()->attach($this->user->id, ['role' => 'member']);

    actingInCanvasTeam($this, $this->team)
        ->withoutVite()
        ->get('/canvas')
        ->assertOk()
        ->assertSee('data-team="'.$this->team->id.'"', false);
});

test('admins can create resources through the canvas api', function () {
    $this->team->members()->attach($this->user->id, ['role' => 'admin']);

    // Passes the permission checks and reaches the controller's validation.
    actingInCanvasTeam($this, $this->team)
        ->postJson('/canvas/api/applications/dockerimage', ['docker_registry_image_name' => 'nginx'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['project_uuid']);
});

test('members only get read access through the canvas api', function () {
    // Owner of another team, but only a member of the session team.
    $this->team->members()->attach($this->user->id, ['role' => 'member']);
    $this->otherTeam->members()->attach($this->user->id, ['role' => 'owner']);

    actingInCanvasTeam($this, $this->team)
        ->getJson('/canvas/api/projects')
        ->assertOk()
        ->assertJsonFragment(['name' => 'canvas-team-project'])
        ->assertJsonMissing(['name' => 'canvas-other-project']);

    actingInCanvasTeam($this, $this->team)
        ->postJson('/canvas/api/applications/dockerimage', ['docker_registry_image_name' => 'nginx'])
        ->assertForbidden();

    actingInCanvasTeam($this, $this->team)
        ->postJson('/canvas/api/deploy', ['uuid' => 'anything'])
        ->assertForbidden();
});

test('admins can add projects and environments through the canvas api', function () {
    $this->team->members()->attach($this->user->id, ['role' => 'admin']);

    $projectUuid = actingInCanvasTeam($this, $this->team)
        ->postJson('/canvas/api/projects', ['name' => 'canvas-new-project'])
        ->assertCreated()
        ->json('uuid');

    $project = Project::query()->where('uuid', $projectUuid)->firstOrFail();
    expect($project->team_id)->toBe($this->team->id)
        ->and($project->environments()->pluck('name')->all())->toBe(['production']);

    $environmentUuid = actingInCanvasTeam($this, $this->team)
        ->postJson("/canvas/api/projects/{$projectUuid}/environments", ['name' => 'staging'])
        ->assertCreated()
        ->json('uuid');

    expect($project->environments()->where('uuid', $environmentUuid)->value('name'))->toBe('staging');
});

test('members cannot add projects or environments, and nobody can add to another team project', function () {
    $this->team->members()->attach($this->user->id, ['role' => 'member']);
    $this->otherTeam->members()->attach($this->user->id, ['role' => 'owner']);

    actingInCanvasTeam($this, $this->team)
        ->postJson('/canvas/api/projects', ['name' => 'canvas-member-project'])
        ->assertForbidden();
    actingInCanvasTeam($this, $this->team)
        ->postJson("/canvas/api/projects/{$this->project->uuid}/environments", ['name' => 'staging'])
        ->assertForbidden();

    // As owner of the other team, the session team's projects are out of reach.
    actingInCanvasTeam($this, $this->otherTeam)
        ->postJson("/canvas/api/projects/{$this->project->uuid}/environments", ['name' => 'staging'])
        ->assertNotFound();

    expect(Project::query()->where('name', 'canvas-member-project')->exists())->toBeFalse()
        ->and($this->project->environments()->where('name', 'staging')->exists())->toBeFalse();
});
