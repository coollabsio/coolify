<?php

use App\Livewire\Project\Show;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::updateOrCreate(['id' => 0]);

    $this->team = Team::factory()->create();

    $this->admin = User::factory()->create();
    $this->admin->teams()->attach($this->team, ['role' => 'admin']);

    $this->member = User::factory()->create();
    $this->member->teams()->attach($this->team, ['role' => 'member']);

    $this->project = Project::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'Test Project',
        'team_id' => $this->team->id,
    ]);
});

test('admin can create environment in project', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    Livewire::test(Show::class, ['project_uuid' => $this->project->uuid])
        ->set('name', 'staging')
        ->call('submit')
        ->assertRedirect();

    expect(Environment::where('name', 'staging')->exists())->toBeTrue();
});

test('member cannot create environment in project', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    Livewire::test(Show::class, ['project_uuid' => $this->project->uuid])
        ->set('name', 'staging')
        ->call('submit')
        ->assertDispatched('error');

    expect(Environment::where('name', 'staging')->exists())->toBeFalse();
});

test('member cannot create environment in project after switching to an owned team', function () {
    $ownTeam = Team::factory()->create();
    $this->member->teams()->attach($ownTeam, ['role' => 'owner']);
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    $component = Livewire::test(Show::class, ['project_uuid' => $this->project->uuid]);

    $this->member->load('teams');
    session(['currentTeam' => $ownTeam]);

    $component->set('name', 'staging')
        ->call('submit')
        ->assertDispatched('error');

    expect(Environment::where('name', 'staging')->exists())->toBeFalse();
});
