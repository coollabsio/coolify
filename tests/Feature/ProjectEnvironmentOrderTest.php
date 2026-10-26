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

test('project page lists production, staging, and development before the other environments', function () {
    Queue::fake();
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $user->teams()->attach($team, ['role' => 'owner']);
    $this->actingAs($user);
    session(['currentTeam' => $team]);

    $project = Project::factory()->create(['team_id' => $team->id]);
    foreach (['deprecated', 'staging', 'qa', 'development', 'demo'] as $name) {
        $project->environments()->create(['name' => $name]);
    }

    Livewire::test(ProjectShow::class, ['project_uuid' => $project->uuid])
        ->assertViewHas('environmentsJs', fn ($environments) => collect($environments)->pluck('name')->all() === ['production', 'staging', 'development', 'demo', 'deprecated', 'qa']);
});
