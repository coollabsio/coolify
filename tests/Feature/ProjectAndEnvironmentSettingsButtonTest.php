<?php

use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->project = Project::factory()->create([
        'team_id' => $this->team->id,
        'name' => 'Test Project',
    ]);

    $this->environment = $this->project->environments()->firstOrFail();
});

test('empty environment resources page header links to the canvas', function () {
    $canvasUrl = route('project.environment.canvas', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ]);

    $this->get(route('project.resource.index', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ]))
        ->assertSuccessful()
        ->assertSee('No resources yet')
        ->assertSee($canvasUrl, false);
});
