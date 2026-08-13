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
        ->assertSee('Settings')
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
        ->assertSee('Settings')
        ->assertSee('Environment settings', false)
        ->assertSee($editUrl, false);
});

test('project show blade includes project settings route', function () {
    $view = file_get_contents(resource_path('views/livewire/project/show.blade.php'));

    expect($view)
        ->toContain("route('project.edit'")
        ->toContain('Project settings')
        ->toContain('name="settings"');
});

test('environment resource index blade includes environment settings route', function () {
    $view = file_get_contents(resource_path('views/livewire/project/resource/index.blade.php'));

    expect($view)
        ->toContain("route('project.environment.edit'")
        ->toContain('Environment settings')
        ->toContain('name="settings"');
});
