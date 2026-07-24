<?php

use App\Livewire\Project\Service\Domains;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();

    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(
        ['id' => 0],
        [
            'id' => 0,
            'is_dns_validation_enabled' => false,
        ]
    ));

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'ip' => '203.0.113.10',
    ]);
    $this->server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
    ]);

    $this->destination = StandaloneDocker::withoutEvents(function () {
        return StandaloneDocker::firstOrCreate(
            [
                'server_id' => $this->server->id,
                'network' => 'coolify',
            ],
            [
                'uuid' => (string) Str::uuid(),
                'name' => 'test-docker',
            ]
        );
    });

    $this->project = Project::factory()->create([
        'team_id' => $this->team->id,
    ]);

    $this->environment = Environment::factory()->create([
        'project_id' => $this->project->id,
    ]);

    $this->service = Service::factory()->create([
        'server_id' => $this->server->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'environment_id' => $this->environment->id,
        'docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n  api:\n    image: node:alpine\n",
    ]);

    $this->webApp = ServiceApplication::create([
        'uuid' => (string) Str::uuid(),
        'service_id' => $this->service->id,
        'name' => 'web',
        'human_name' => 'Web',
        'image' => 'nginx:alpine',
        'fqdn' => null,
    ]);

    $this->apiApp = ServiceApplication::create([
        'uuid' => (string) Str::uuid(),
        'service_id' => $this->service->id,
        'name' => 'api',
        'human_name' => 'API',
        'image' => 'node:alpine',
        'fqdn' => 'https://api.example.com',
    ]);
});

it('lists domains grouped by service application on the stack domains page', function () {
    Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])])
        ->assertSuccessful()
        ->assertSee('API')
        ->assertSee('https://api.example.com')
        ->assertSee('Web');
});

it('adds a domain to a selected service application', function () {
    Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])])
        ->set('newServiceApplicationId', $this->webApp->id)
        ->set('newDomain', 'https://web.example.com')
        ->call('addDomain')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    $this->webApp->refresh();
    expect($this->webApp->fqdn)->toBe('https://web.example.com');
});

it('loads persisted dns status for service applications', function () {
    $this->apiApp->update([
        'domain_dns_statuses' => [
            'https://api.example.com' => [
                'status' => 'failed',
                'message' => 'DNS mismatch stored.',
                'expected_ip' => '203.0.113.10',
                'checked_at' => now()->toIso8601String(),
            ],
        ],
    ]);

    Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])])
        ->assertSee('DNS mismatch stored.');
});

it('hides dns message text when service domain dns status is ok', function () {
    $this->apiApp->update([
        'domain_dns_statuses' => [
            'https://api.example.com' => [
                'status' => 'ok',
                'message' => 'DNS points to 203.0.113.10 (or Cloudflare).',
                'expected_ip' => '203.0.113.10',
                'checked_at' => now()->toIso8601String(),
            ],
        ],
    ]);

    Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])])
        ->assertSee('DNS OK')
        ->assertDontSee('DNS points to 203.0.113.10')
        ->assertDontSee('Last checked');
});

it('exposes the stack domains route', function () {
    $url = route('project.service.domains', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'service_uuid' => $this->service->uuid,
    ]);

    $this->get($url)
        ->assertSuccessful()
        ->assertSeeLivewire(Domains::class)
        ->assertSee('Domains');
});
