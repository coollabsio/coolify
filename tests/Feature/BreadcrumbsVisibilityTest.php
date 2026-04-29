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
    $response->assertSee('flex min-w-0 flex-col gap-1 md:hidden', false);
    $response->assertSee('flex min-w-0 items-center text-xs text-neutral-400', false);
    $response->assertSee('hidden flex-wrap items-center gap-y-1 md:flex', false);
    $response->assertSee('flex flex-wrap items-center gap-1', false);
    $response->assertSee(
        'scrollbar flex min-h-10 w-full flex-nowrap items-center gap-6 overflow-x-scroll overflow-y-hidden pb-1 whitespace-nowrap md:w-auto md:overflow-visible',
        false,
    );
    $response->assertSee('shrink-0', false);
    $response->assertSee('Actions');
    $response->assertSee('dropdown-item-touch', false);
    $response->assertSee('hidden flex-wrap items-center gap-2 md:flex', false);
    $response->assertSee('window.innerWidth >= 768', false);
    $response->assertSee(':style="panelStyles"', false);
    $response->assertSee('absolute top-full z-50 mt-1 min-w-max max-w-[calc(100vw-1rem)] md:top-0 md:mt-6', false);
    $response->assertSee('Pure Dockerfile Example');
    $response->assertSee('Running');
    $response->assertSee('pt-2 pb-4 md:pb-10', false);

    expect($response->getContent())->not->toContain('hidden pt-2 pb-10 md:flex');
});
