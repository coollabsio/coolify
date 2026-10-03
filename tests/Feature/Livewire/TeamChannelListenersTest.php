<?php

use App\Livewire\LayoutPopups;
use App\Livewire\Project\Application\ServerStatusBadge;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    $this->team = Team::factory()->create();
    $this->otherTeam = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->otherTeam->members()->attach($this->user->id, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

it('keeps team broadcast listeners of the mounted team after the session team changes', function () {
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $application = Application::factory()->create(['environment_id' => $environment->id]);

    $component = Livewire::test(ServerStatusBadge::class, ['application' => $application]);

    // A team switch in another browser tab changes the shared session.
    session(['currentTeam' => $this->otherTeam]);

    $component
        ->dispatch("echo-private:team.{$this->team->id},ServiceChecked", ['teamId' => $this->team->id])
        ->assertOk();
});

it('runs team broadcast handlers while the session team matches the mounted team', function () {
    Livewire::test(LayoutPopups::class)
        ->dispatch("echo-private:team.{$this->team->id},TestEvent")
        ->assertDispatched('success');
});

it('ignores team broadcasts of the mounted team after the session team changes', function () {
    $component = Livewire::test(LayoutPopups::class);

    session(['currentTeam' => $this->otherTeam]);

    expect($component->instance()->getListeners())
        ->toBe(["echo-private:team.{$this->team->id},TestEvent" => 'ignoreStaleTeamChannelEvent']);

    $component
        ->dispatch("echo-private:team.{$this->team->id},TestEvent")
        ->assertOk()
        ->assertNotDispatched('success');
});

it('registers no team broadcast listeners without a current team', function () {
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $application = Application::factory()->create(['environment_id' => $environment->id]);
    session()->forget('currentTeam');

    $component = Livewire::test(ServerStatusBadge::class, ['application' => $application]);

    expect($component->instance()->getListeners())->toBe([]);
});
