<?php

use App\Livewire\Project\Show as ProjectShow;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

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

test('project show page header links to project settings', function () {
    $editUrl = route('project.edit', ['project_uuid' => $this->project->uuid]);

    Livewire::test(ProjectShow::class, ['project_uuid' => $this->project->uuid])
        ->assertSee('Open settings for Test Project', false)
        ->assertSee('Project settings', false)
        ->assertSeeHtml($editUrl);
});

test('environment resources page header links to environment settings', function () {
    $editUrl = route('project.environment.edit', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ]);

    $this->get(route('project.resource.index', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ]))
        ->assertSuccessful()
        ->assertSee('Open settings for '.$this->environment->name, false)
        ->assertSee('Environment settings', false)
        ->assertSee($editUrl, false);
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
