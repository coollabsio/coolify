<?php

use App\Livewire\Dashboard;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use App\Services\DashboardHealthcheckAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));

    $this->team = Team::factory()->create();
    $this->otherTeam = Team::factory()->create();
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

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
    ]);

    $this->project = Project::factory()->create([
        'team_id' => $this->team->id,
        'name' => 'Alert Project',
    ]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);

    $this->destination = StandaloneDocker::factory()->create([
        'server_id' => $this->server->id,
        'name' => 'dest-'.fake()->unique()->word(),
        'network' => 'coolify-'.fake()->unique()->word(),
    ]);
});

function createApplication(array $attributes = []): Application
{
    return Application::factory()->create(array_merge([
        'environment_id' => test()->environment->id,
        'destination_id' => test()->destination->id,
        'destination_type' => StandaloneDocker::class,
        'git_repository' => 'https://github.com/coollabsio/coolify',
        'git_branch' => 'main',
    ], $attributes));
}

function createService(array $attributes = []): Service
{
    return Service::factory()->create(array_merge([
        'server_id' => test()->server->id,
        'destination_id' => test()->destination->id,
        'destination_type' => StandaloneDocker::class,
        'environment_id' => test()->environment->id,
    ], $attributes));
}

it('includes exited applications without healthcheck', function () {
    createApplication([
        'name' => 'Down App',
        'status' => 'exited',
        'health_check_enabled' => false,
        'custom_healthcheck_found' => false,
    ]);

    $alerts = app(DashboardHealthcheckAlertService::class)->downWithoutHealthcheckForTeam();

    expect($alerts)->toHaveCount(1)
        ->and($alerts->first()['name'])->toBe('Down App')
        ->and($alerts->first()['type'])->toBe('application');
});

it('excludes running applications without healthcheck', function () {
    createApplication([
        'name' => 'Running App',
        'status' => 'running:healthy',
        'health_check_enabled' => false,
        'custom_healthcheck_found' => false,
    ]);

    $alerts = app(DashboardHealthcheckAlertService::class)->downWithoutHealthcheckForTeam();

    expect($alerts)->toBeEmpty();
});

it('excludes exited applications with healthcheck enabled', function () {
    createApplication([
        'name' => 'Monitored App',
        'status' => 'exited',
        'health_check_enabled' => true,
        'custom_healthcheck_found' => false,
    ]);

    $alerts = app(DashboardHealthcheckAlertService::class)->downWithoutHealthcheckForTeam();

    expect($alerts)->toBeEmpty();
});

it('excludes exited applications with custom dockerfile healthcheck', function () {
    createApplication([
        'name' => 'Dockerfile HC App',
        'status' => 'exited',
        'health_check_enabled' => false,
        'custom_healthcheck_found' => true,
    ]);

    $alerts = app(DashboardHealthcheckAlertService::class)->downWithoutHealthcheckForTeam();

    expect($alerts)->toBeEmpty();
});

it('includes exited standalone databases without healthcheck', function () {
    StandalonePostgresql::factory()->create([
        'team_id' => $this->team->id,
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => StandaloneDocker::class,
        'name' => 'Down DB',
        'status' => 'exited',
        'health_check_enabled' => false,
    ]);

    $alerts = app(DashboardHealthcheckAlertService::class)->downWithoutHealthcheckForTeam();

    expect($alerts)->toHaveCount(1)
        ->and($alerts->first()['name'])->toBe('Down DB')
        ->and($alerts->first()['type'])->toBe('database');
});

it('includes exited service containers without compose healthcheck', function () {
    $service = createService([
        'name' => 'WordPress Stack',
        'docker_compose_raw' => <<<'YAML'
services:
  web:
    image: wordpress:latest
YAML,
    ]);

    ServiceApplication::create([
        'service_id' => $service->id,
        'uuid' => (string) Str::uuid(),
        'name' => 'web',
        'human_name' => 'Web Container',
        'status' => 'exited',
    ]);

    $alerts = app(DashboardHealthcheckAlertService::class)->downWithoutHealthcheckForTeam();

    expect($alerts)->toHaveCount(1)
        ->and($alerts->first()['name'])->toBe('Web Container')
        ->and($alerts->first()['parent_name'])->toBe('WordPress Stack')
        ->and($alerts->first()['type'])->toBe('service');
});

it('excludes exited service containers with compose healthcheck', function () {
    $service = createService([
        'docker_compose_raw' => <<<'YAML'
services:
  web:
    image: wordpress:latest
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost"]
      interval: 30s
YAML,
    ]);

    ServiceApplication::create([
        'service_id' => $service->id,
        'uuid' => (string) Str::uuid(),
        'name' => 'web',
        'status' => 'exited',
    ]);

    $alerts = app(DashboardHealthcheckAlertService::class)->downWithoutHealthcheckForTeam();

    expect($alerts)->toBeEmpty();
});

it('includes exited service containers excluded from healthcheck in compose', function () {
    $service = createService([
        'docker_compose_raw' => <<<'YAML'
services:
  worker:
    image: worker:latest
    exclude_from_hc: true
    healthcheck:
      test: ["CMD", "true"]
YAML,
    ]);

    ServiceApplication::create([
        'service_id' => $service->id,
        'uuid' => (string) Str::uuid(),
        'name' => 'worker',
        'status' => 'exited',
    ]);

    $alerts = app(DashboardHealthcheckAlertService::class)->downWithoutHealthcheckForTeam();

    expect($alerts)->toHaveCount(1)
        ->and($alerts->first()['name'])->toBe('worker');
});

it('does not include resources from other teams', function () {
    $otherProject = Project::factory()->create(['team_id' => $this->otherTeam->id]);
    $otherEnvironment = Environment::factory()->create(['project_id' => $otherProject->id]);
    $otherServer = Server::factory()->create(['team_id' => $this->otherTeam->id]);
    $otherDestination = StandaloneDocker::factory()->create(['server_id' => $otherServer->id]);

    Application::factory()->create([
        'environment_id' => $otherEnvironment->id,
        'destination_id' => $otherDestination->id,
        'destination_type' => StandaloneDocker::class,
        'name' => 'Other Team App',
        'status' => 'exited',
        'health_check_enabled' => false,
        'custom_healthcheck_found' => false,
        'git_repository' => 'https://github.com/coollabsio/coolify',
        'git_branch' => 'main',
    ]);

    $alerts = app(DashboardHealthcheckAlertService::class)->downWithoutHealthcheckForTeam();

    expect($alerts)->toBeEmpty();
});

it('renders dashboard healthcheck alert section', function () {
    createApplication([
        'name' => 'Down App Dashboard',
        'status' => 'exited',
        'health_check_enabled' => false,
        'custom_healthcheck_found' => false,
    ]);

    Livewire::test(Dashboard::class)
        ->assertSee('Down without healthcheck')
        ->assertSee('Down App Dashboard')
        ->assertSee('Enable healthcheck')
        ->assertDontSee('No stopped resources without healthcheck detected.');
});

it('refreshes healthcheck alerts without error', function () {
    createApplication([
        'name' => 'Down App Refresh',
        'status' => 'exited',
        'health_check_enabled' => false,
        'custom_healthcheck_found' => false,
    ]);

    Livewire::test(Dashboard::class)
        ->call('refreshStats')
        ->assertSet('downWithoutHealthcheck.0.name', 'Down App Refresh');
});

it('shows empty state when no alerts exist', function () {
    Livewire::test(Dashboard::class)
        ->assertSee('No stopped resources without healthcheck detected.');
});
