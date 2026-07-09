<?php

use App\Livewire\Dashboard;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Services\DashboardStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

it('aggregates dashboard stats for the current team', function () {
    $stats = app(DashboardStatsService::class)->forTeam();
    expect($stats)->toHaveKeys(['servers', 'projects', 'applications', 'services', 'databases']);
});

it('renders dashboard kpis and latest deployments', function () {
    Livewire::test(Dashboard::class)
        ->assertSee('Servers')
        ->assertSee('Latest Deployments')
        ->assertSee('Connected Servers');
});
