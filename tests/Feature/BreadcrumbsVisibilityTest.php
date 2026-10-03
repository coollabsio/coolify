<?php

use App\Models\Application;
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

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'name' => 'Pure Dockerfile Example',
        'status' => 'running',
    ]);
});

it('hides the breadcrumb trail on mobile while keeping the current status visible', function () {
    $response = $this->get(route('project.application.configuration', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'application_uuid' => $this->application->uuid,
    ]));

    $response->assertSuccessful();

    // The resource breadcrumb lives in the top bar (project / environment / resource switchers).
    $response->assertSee('title="Switch project"', false);
    $response->assertSee('title="Switch environment"', false);
    $response->assertSee('title="Switch resource"', false);

    // Desktop resource actions dock into the top bar; the slot is hidden below xl.
    $response->assertSee('<div id="resource-action-hud-slot" class="hidden shrink-0 items-center xl:flex"></div>', false);

    // Below xl, the heading keeps the resource name, current status, and a split action visible.
    expect(preg_match(
        '/<div class="mb-3 w-full xl:hidden">.*?<h1[^>]*>\s*Pure Dockerfile Example\s*<\/h1>.*?Application status/s',
        $response->getContent()
    ))->toBe(1);
    $response->assertSee('id="application-mobile-actions"', false);
    $response->assertSee('Running');

    expect($response->getContent())->not->toContain('hidden pt-2 pb-10 md:flex');
});
