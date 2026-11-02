<?php

use App\Livewire\Dashboard;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cookie;
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

it('shows the projects and servers sections as tables by default', function () {
    Livewire::test(Dashboard::class)
        ->assertSee("viewMode: 'table'", false)
        ->assertDontSee("viewMode: 'grid'", false);
});

it('remembers the view of each dashboard section on its own', function () {
    Livewire::test(Dashboard::class)->call('saveListView', 'dashboard_projects', 'grid');

    expect(Cookie::queued("coolify_{$this->user->id}_dashboard_projects_view")?->getValue())->toBe('grid')
        ->and(Cookie::queued("coolify_{$this->user->id}_dashboard_servers_view"))->toBeNull();

    $this->withCookie("coolify_{$this->user->id}_dashboard_projects_view", 'grid')
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee("viewMode: 'grid'", false)
        ->assertSee("viewMode: 'table'", false);
});

it('links to all projects only when the dashboard hides some of them', function () {
    Project::factory()->count(8)->create(['team_id' => $this->team->id]);

    Livewire::test(Dashboard::class)->assertDontSee(route('project.index'), false);

    Project::factory()->create(['team_id' => $this->team->id]);

    Livewire::test(Dashboard::class)->assertSee(route('project.index'), false);
});
