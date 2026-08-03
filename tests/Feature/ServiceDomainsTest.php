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

it('does not persist service redirect until Set Direction is called', function () {
    $this->webApp->update(['fqdn' => 'https://web.example.com', 'redirect' => 'both']);

    Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])])
        ->set("serviceRedirects.{$this->webApp->id}", 'www');

    expect($this->webApp->fresh()->redirect)->toBe('both')
        ->and($this->webApp->fresh()->fqdn)->toBe('https://web.example.com');
});

it('sets redirect direction per service application without changing other apps', function () {
    $this->webApp->update(['fqdn' => 'https://web.example.com', 'redirect' => 'both']);
    $this->apiApp->update(['redirect' => 'both']);

    Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])])
        ->set("serviceRedirects.{$this->webApp->id}", 'www')
        ->call('setServiceRedirect', $this->webApp->id)
        ->assertDispatched('success');

    expect($this->webApp->fresh()->redirect)->toBe('www')
        ->and(explode(',', (string) $this->webApp->fresh()->fqdn))
        ->toContain('https://web.example.com')
        ->toContain('https://www.web.example.com')
        ->and($this->apiApp->fresh()->redirect)->toBe('both')
        ->and($this->apiApp->fresh()->fqdn)->toBe('https://api.example.com');
});

it('auto-adds missing non-www pair for a service application redirect', function () {
    $this->apiApp->update([
        'fqdn' => 'https://www.api.example.com',
        'redirect' => 'both',
    ]);

    Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])])
        ->set("serviceRedirects.{$this->apiApp->id}", 'non-www')
        ->call('setServiceRedirect', $this->apiApp->id)
        ->assertDispatched('success');

    $this->apiApp->refresh();

    expect($this->apiApp->redirect)->toBe('non-www')
        ->and(explode(',', (string) $this->apiApp->fqdn))
        ->toContain('https://www.api.example.com')
        ->toContain('https://api.example.com');
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

it('prunes dns status when a service domain is removed', function () {
    $this->apiApp->update([
        'domain_dns_statuses' => [
            'https://api.example.com' => [
                'status' => 'failed',
                'message' => 'Stale DNS result.',
                'expected_ip' => '203.0.113.10',
                'checked_at' => now()->toIso8601String(),
            ],
        ],
    ]);

    Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])])
        ->call('removeDomain', 0)
        ->assertDispatched('success');

    expect($this->apiApp->fresh()->domain_dns_statuses)->toBeNull();
});

it('prunes the previous dns status when a service domain is renamed', function () {
    $this->apiApp->update([
        'domain_dns_statuses' => [
            'https://api.example.com' => [
                'status' => 'ok',
                'message' => 'Stale DNS result.',
                'expected_ip' => '203.0.113.10',
                'checked_at' => now()->toIso8601String(),
            ],
        ],
    ]);

    Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])])
        ->call('startEdit', 0)
        ->set('editingDomain', 'https://renamed.example.com')
        ->call('updateDomain')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    $this->apiApp->refresh();

    expect($this->apiApp->fqdn)->toBe('https://renamed.example.com')
        ->and($this->apiApp->domain_dns_statuses)->toBeNull();
});

it('does not restore stale dns status when a removed service domain is re-added', function () {
    $this->apiApp->update([
        'domain_dns_statuses' => [
            'https://api.example.com' => [
                'status' => 'failed',
                'message' => 'Stale DNS result.',
                'expected_ip' => '203.0.113.10',
                'checked_at' => now()->toIso8601String(),
            ],
        ],
    ]);

    Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])])
        ->call('removeDomain', 0)
        ->set('newServiceApplicationId', $this->apiApp->id)
        ->set('newDomain', 'https://api.example.com')
        ->call('addDomain')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    $this->apiApp->refresh();

    expect($this->apiApp->fqdn)->toBe('https://api.example.com')
        ->and($this->apiApp->domain_dns_statuses)->toBeNull();
});

it('saves after confirming both a domain conflict and a missing required port', function () {
    $this->service->update([
        'docker_compose_raw' => <<<'YAML'
services:
  web:
    image: nginx:alpine
    environment:
      SERVICE_URL_WEB_8000: ""
  api:
    image: node:alpine
YAML,
    ]);

    $component = Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])])
        ->set('newServiceApplicationId', $this->webApp->id)
        ->set('newDomain', 'https://api.example.com')
        ->call('addDomain')
        ->assertSet('showDomainConflictModal', true)
        ->call('confirmDomainUsage')
        ->assertSet('showDomainConflictModal', false)
        ->assertSet('showPortWarningModal', true)
        ->assertSet('forceSaveDomains', true);

    $component
        ->call('confirmRemovePort')
        ->assertSet('showPortWarningModal', false)
        ->assertSet('forceSaveDomains', false)
        ->assertSet('forceRemovePort', false)
        ->assertSet('pendingAction', null)
        ->assertDispatched('success');

    expect($this->webApp->fresh()->fqdn)->toBe('https://api.example.com');
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

it('forbids read-only users from checking service domain dns', function (string $action, array $parameters) {
    $this->team->members()->updateExistingPivot($this->user->id, ['role' => 'member']);

    Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])])
        ->call($action, ...$parameters)
        ->assertForbidden();

    expect($this->apiApp->fresh()->domain_dns_statuses)->toBeNull();
})->with([
    'all domains' => ['checkAllDns', []],
    'one domain' => ['checkDomainDns', [0]],
]);

it('hides dns check controls from read-only users', function () {
    $this->team->members()->updateExistingPivot($this->user->id, ['role' => 'member']);

    Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])])
        ->assertDontSee('Recheck DNS')
        ->assertDontSee('Check DNS');
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
