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

    $this->privateKey = PrivateKey::create([
        'uuid' => (string) Str::uuid(),
        'team_id' => $this->team->id,
        'name' => 'Test Key',
        'description' => 'Test SSH key',
        'private_key' => '-----BEGIN OPENSSH PRIVATE KEY-----
b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW
QyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevAAAAJi/QySHv0Mk
hwAAAAtzc2gtZWQyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevA
AAAECBQw4jg1WRT2IGHMncCiZhURCts2s24HoDS0thHnnRKVuGmoeGq/pojrsyP1pszcNV
uZx9iFkCELtxrh31QJ68AAAAEXNhaWxANzZmZjY2ZDJlMmRkAQIDBA==
-----END OPENSSH PRIVATE KEY-----',
    ]);

    $this->reachableServer = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
    ]);
    $this->reachableServer->settings->update(['is_reachable' => true, 'force_disabled' => false]);

    $this->unreachableServer = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
    ]);
    $this->unreachableServer->settings->update(['is_reachable' => false]);

    $this->emptyProject = Project::factory()->create([
        'team_id' => $this->team->id,
        'name' => 'Empty Project',
    ]);

    $this->activeProject = Project::factory()->create([
        'team_id' => $this->team->id,
        'name' => 'Active Project',
    ]);
    $this->environment = Environment::factory()->create(['project_id' => $this->activeProject->id]);

    $this->destination = StandaloneDocker::factory()->create([
        'server_id' => $this->reachableServer->id,
        'name' => 'dest-'.fake()->unique()->word(),
        'network' => 'coolify-'.fake()->unique()->word(),
    ]);

    $this->runningApplication = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => StandaloneDocker::class,
        'status' => 'running:healthy',
        'name' => 'Running App',
        'git_repository' => 'https://github.com/coollabsio/coolify',
        'git_branch' => 'main',
    ]);

    $this->exitedApplication = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => StandaloneDocker::class,
        'status' => 'exited',
        'name' => 'Stopped App',
        'git_repository' => 'https://github.com/coollabsio/coolify',
        'git_branch' => 'main',
    ]);

    ApplicationDeploymentQueue::create([
        'application_id' => (string) $this->runningApplication->id,
        'deployment_uuid' => (string) Str::uuid(),
        'status' => 'finished',
        'commit' => 'a5ac005deadbeef',
        'commit_message' => 'Fix deployment dashboard',
        'server_id' => $this->reachableServer->id,
        'application_name' => $this->runningApplication->name,
        'server_name' => $this->reachableServer->name,
        'finished_at' => now()->subHour(),
        'is_webhook' => true,
    ]);
});

it('aggregates dashboard stats for the current team', function () {
    $stats = app(DashboardStatsService::class)->forTeam();

    expect($stats['servers']['total'])->toBe(2)
        ->and($stats['servers']['active'])->toBe(1)
        ->and($stats['servers']['inactive'])->toBe(1)
        ->and($stats['projects']['total'])->toBe(2)
        ->and($stats['projects']['active'])->toBe(1)
        ->and($stats['projects']['inactive'])->toBe(1)
        ->and($stats['applications']['total'])->toBe(2)
        ->and($stats['applications']['active'])->toBe(1)
        ->and($stats['applications']['inactive'])->toBe(1);
});

it('renders dashboard kpis and latest deployments', function () {
    Livewire::test(Dashboard::class)
        ->assertSee('Servers')
        ->assertSee('Projects')
        ->assertSee('Applications')
        ->assertSee('Latest Deployments')
        ->assertSee('Connected Servers')
        ->assertSee('Active Project')
        ->assertSee('Running App')
        ->assertSee('View deployments')
        ->assertSee('Success')
        ->assertSee($this->reachableServer->name)
        ->assertSee('Connected')
        ->assertSee($this->unreachableServer->name)
        ->assertSee('Disconnected')
        ->assertDontSee('Fix deployment dashboard')
        ->assertDontSee('Started:')
        ->assertDontSee('Manual')
        ->assertSeeHtml(route('project.application.deployment.index', [
            'project_uuid' => $this->activeProject->uuid,
            'environment_uuid' => $this->environment->uuid,
            'application_uuid' => $this->runningApplication->uuid,
        ]))
        ->assertSeeHtml(route('server.show', ['server_uuid' => $this->reachableServer->uuid]));
});

it('refreshes dashboard stats without error', function () {
    Livewire::test(Dashboard::class)
        ->call('refreshStats')
        ->assertSet('stats.servers.total', 2)
        ->assertSet('stats.servers.active', 1);
});

it('limits latest deployments to five', function () {
    foreach (range(1, 6) as $index) {
        ApplicationDeploymentQueue::create([
            'application_id' => (string) $this->runningApplication->id,
            'deployment_uuid' => (string) Str::uuid(),
            'status' => 'finished',
            'commit' => 'deadbeef00000'.$index,
            'commit_message' => "Deployment {$index}",
            'server_id' => $this->reachableServer->id,
            'application_name' => $this->runningApplication->name,
            'server_name' => $this->reachableServer->name,
            'finished_at' => now()->subMinutes($index),
            'is_webhook' => false,
        ]);
    }

    expect(Livewire::test(Dashboard::class)->instance()->latestDeployments)->toHaveCount(5);
});

it('does not include other team resources in dashboard stats', function () {
    $otherTeam = Team::factory()->create();
    $otherUser = User::factory()->create();
    $otherTeam->members()->attach($otherUser->id, ['role' => 'owner']);

    $otherServer = Server::factory()->create([
        'team_id' => $otherTeam->id,
        'private_key_id' => $this->privateKey->id,
    ]);
    $otherServer->settings->update(['is_reachable' => true]);

    $stats = app(DashboardStatsService::class)->forTeam();

    expect($stats['servers']['total'])->toBe(2);
});
