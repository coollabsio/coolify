<?php

use App\Livewire\Project\Index;
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
});

it('shows an empty state when there are no projects', function () {
    Livewire::test(Index::class)
        ->assertSee('No projects yet')
        ->assertSee('Create a project to organize your environments and resources.')
        ->assertSee('Open onboarding');
});

it('does not show the empty state when projects exist', function () {
    Project::factory()->create([
        'team_id' => $this->team->id,
        'name' => 'Test Project',
        'description' => 'A project description',
    ]);

    Livewire::test(Index::class)
        ->assertSee('Test Project')
        ->assertDontSee('No projects yet');
});
