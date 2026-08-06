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

it('shows dns entries control next to Add', function () {
    Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])])
        ->assertSuccessful()
        ->assertSee('DNS entries')
        ->assertSee('Manual records');
});

it('rotates the dns entries chevron while its dropdown is open', function () {
    $view = file_get_contents(resource_path('views/livewire/project/shared/cloudflare-autoconfigure.blade.php'));

    expect($view)
        ->toContain('class="inline-flex transition-transform"')
        ->toContain(':class="dnsEntriesOpen && \'rotate-180\'"');
});

it('lists dns entries for service hosts that still need dns', function () {
    $this->webApp->update([
        'fqdn' => 'https://web.example.com',
        'domain_dns_statuses' => [
            'https://web.example.com' => [
                'status' => 'ok',
                'message' => 'OK',
                'expected_ip' => '203.0.113.10',
                'checked_at' => now()->toIso8601String(),
            ],
        ],
    ]);
    $this->apiApp->update(['fqdn' => 'https://api.example.com,https://www.api.example.com']);

    $component = Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])])
        ->call('openDnsRecordsModal')
        ->assertSee('Recheck');

    $names = collect($component->instance()->dnsRecordHints())->pluck('name')->all();

    expect($names)
        ->toContain('api.example.com')
        ->toContain('www.api.example.com')
        ->not->toContain('web.example.com');
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

it('auto-adds the suggested www pair when adding a service domain with both directions', function () {
    $this->webApp->update(['redirect' => 'both']);

    Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])])
        ->set('newServiceApplicationId', $this->webApp->id)
        ->set('newDomain', 'https://web.example.com')
        ->call('addDomain')
        ->assertHasNoErrors()
        ->assertDispatched('success')
        ->assertSee('DNS skipped');

    expect(explode(',', (string) $this->webApp->fresh()->fqdn))
        ->toContain('https://web.example.com')
        ->toContain('https://www.web.example.com');
});

it('adds a domain to a selected service application', function () {
    Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])])
        ->set('newServiceApplicationId', $this->webApp->id)
        ->set('newDomain', 'https://web.example.com')
        ->call('addDomain')
        ->assertHasNoErrors()
        ->assertDispatched('success')
        ->assertSet('domainRows', fn (array $rows): bool => collect($rows)->pluck('url')->contains('https://web.example.com'))
        ->assertSee('https://web.example.com');

    $this->webApp->refresh();
    expect(explode(',', (string) $this->webApp->fqdn))
        ->toBe(['https://web.example.com', 'https://www.web.example.com']);

    $dnsStatuses = $this->webApp->domain_dns_statuses;

    expect($dnsStatuses)
        ->toHaveKey('https://web.example.com')
        ->toHaveKey('https://www.web.example.com')
        ->and($dnsStatuses['https://web.example.com']['status'])
        ->toBe('skipped')
        ->and($dnsStatuses['https://web.example.com']['checked_at'])
        ->not->toBeNull();
});

it('replaces the rendered domain list when its rows change', function () {
    $view = file_get_contents(resource_path('views/livewire/project/service/domains.blade.php'));

    expect($view)->toContain('wire:key="service-domains-list-{{ md5(serialize($domainRows)) }}"');
});

it('does not duplicate the service name as a badge in the domain cell', function () {
    $view = file_get_contents(resource_path('views/livewire/project/service/partials/domain-table.blade.php'));

    expect($view)->not->toContain('domains-service-mobile table-badge');
});

it('rolls back a domain change when compose regeneration fails', function () {
    $this->service->update(['docker_compose_raw' => '']);

    Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])])
        ->set('newServiceApplicationId', $this->webApp->id)
        ->set('newDomain', 'https://web.example.com')
        ->call('addDomain')
        ->assertDispatched('error')
        ->assertNotDispatched('success');

    expect($this->webApp->fresh()->fqdn)->toBeNull();
});

it('rolls back a redirect change when compose regeneration fails', function () {
    $this->webApp->update(['fqdn' => 'https://web.example.com', 'redirect' => 'both']);
    $this->service->update(['docker_compose_raw' => '']);

    Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])])
        ->set("serviceRedirects.{$this->webApp->id}", 'www')
        ->call('setServiceRedirect', $this->webApp->id)
        ->assertDispatched('error')
        ->assertNotDispatched('success');

    expect($this->webApp->fresh()->redirect)->toBe('both')
        ->and($this->webApp->fresh()->fqdn)->toBe('https://web.example.com');
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
        ->and($this->apiApp->domain_dns_statuses)->not->toHaveKey('https://api.example.com')
        ->and($this->apiApp->domain_dns_statuses)->toHaveKey('https://renamed.example.com')
        ->and($this->apiApp->domain_dns_statuses['https://renamed.example.com']['status'])->toBe('skipped');
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

    expect(explode(',', (string) $this->apiApp->fqdn))
        ->toBe(['https://api.example.com', 'https://www.api.example.com'])
        ->and($this->apiApp->domain_dns_statuses['https://api.example.com']['status'] ?? null)->toBe('skipped')
        ->and($this->apiApp->domain_dns_statuses['https://api.example.com']['message'] ?? null)->not->toBe('Stale DNS result.');
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

    expect(explode(',', (string) $this->webApp->fresh()->fqdn))
        ->toBe(['https://api.example.com', 'https://www.api.example.com']);
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
        ->assertSee('DNS mismatch')
        ->assertDontSee('DNS mismatch stored.')
        ->call('openDnsRecordsModal')
        ->assertSet('showDnsRecordsModal', true);
});

it('shows service dns mismatches before other domain entries', function () {
    $this->webApp->update([
        'fqdn' => 'https://healthy.example.com',
        'domain_dns_statuses' => [
            'https://healthy.example.com' => ['status' => 'ok', 'message' => 'OK'],
        ],
    ]);
    $this->apiApp->update([
        'fqdn' => 'https://broken.example.com',
        'domain_dns_statuses' => [
            'https://broken.example.com' => ['status' => 'failed', 'message' => 'Mismatch'],
        ],
    ]);

    Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])])
        ->assertSet('domainRows.0.url', 'https://broken.example.com')
        ->assertSet('domainRows.0.dns_status', 'failed')
        ->assertSet('domainRows.2.url', 'https://healthy.example.com');
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
        ->assertDontSee('DNS points to 203.0.113.10');
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
