<?php

use App\Jobs\CheckDomainDnsJob;
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
use Illuminate\Support\Facades\Queue;
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

it('groups configured domains and shows redirect settings in the table', function () {
    $this->apiApp->update([
        'fqdn' => 'https://api.example.com,https://admin.example.com',
    ]);

    $html = Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])])
        ->assertSuccessful()
        ->assertSee('API')
        ->assertSee('https://api.example.com')
        ->assertSee('https://admin.example.com')
        ->html();

    expect($html)
        ->toContain("service-domain-group-{$this->apiApp->id}")
        ->toContain("id=\"service-domain-direction-{$this->apiApp->id}-0-trigger\"")
        ->toContain("id=\"service-domain-indexing-{$this->apiApp->id}-0-trigger\"")
        ->toContain('src="https://api.example.com/favicon.ico"')
        ->toContain('class="relative size-4 shrink-0"')
        ->toContain('domain-favicon-fallback')
        ->toContain('class="invisible absolute inset-0 size-4 rounded-sm"')
        ->toContain('$el.previousElementSibling.classList.add(\'hidden\')')
        ->toContain('x-on:error="$el.remove()"')
        ->toContain('class="min-w-0 flex-1 text-[13px]')
        ->toContain('class="listbox-trigger"')
        ->toContain('application-settings-section-body is-flush mt-1 w-full scroll-mt-28 overflow-visible')
        ->toContain('dark:bg-white/[0.04]')
        ->toContain('<span>Domain</span>')
        ->toContain('<span>DNS Check</span>')
        ->not->toContain('<span>Last checked</span>')
        ->not->toContain("service-domain-group-{$this->webApp->id}")
        ->and(substr_count($html, '2 domains'))->toBe(1)
        ->and(strpos($html, '>API</span>'))->toBeLessThan(strpos($html, '<span>Domain</span>'))
        ->and(substr_count($html, "id=\"service-domain-group-{$this->apiApp->id}\""))->toBe(1);
});

it('shows and persists the HTTP redirect control for HTTPS service applications', function () {
    Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])])
        ->assertSee('Redirect HTTP to HTTPS')
        ->assertSee('Keep enabled when Cloudflare uses Full or Full (Strict) SSL.')
        ->call('updateForceHttps', $this->apiApp->id, false)
        ->assertHasNoErrors();

    expect($this->apiApp->fresh()->is_force_https_enabled)->toBeFalse();
    expect($this->service->fresh()->docker_compose)->not->toContain('middlewares=redirect-to-https');
});

it('hides the HTTP redirect control for HTTP-only service applications', function () {
    $this->apiApp->update(['fqdn' => 'http://api.example.com']);

    Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])])
        ->assertDontSee('Redirect HTTP to HTTPS');
});

it('shows one redirect control for each www and non-www pair', function () {
    $this->apiApp->update([
        'fqdn' => 'https://api.example.com,https://www.api.example.com,https://admin.example.com,https://www.admin.example.com',
    ]);

    $html = Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])])
        ->assertSuccessful()
        ->html();

    expect(substr_count($html, 'this.$wire.updateServiceRedirect('))->toBe(2);
});

it('uses segmented fields when adding and editing service domains', function () {
    $view = file_get_contents(resource_path('views/livewire/project/service/domains.blade.php'));

    expect($view)
        ->toContain('<x-forms.domain-input id="newDomainParts"')
        ->toContain('<x-forms.domain-input id="editingDomainParts"')
        ->not->toContain('placeholder="https://app.example.com"');
});

it('resets the add domain dns gate when segmented domain fields change', function () {
    Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])])
        ->set('addDomainDnsFailed', true)
        ->set('addDomainDnsMessage', 'DNS validation failed.')
        ->set('forceSaveDns', true)
        ->set('newDomainParts.host', 'web.example.com')
        ->assertSet('newDomainPartsChanged', true)
        ->assertSet('addDomainDnsFailed', false)
        ->assertSet('addDomainDnsMessage', '')
        ->assertSet('forceSaveDns', false);
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

it('persists a service redirect when its dropdown changes', function () {
    $this->webApp->update(['fqdn' => 'https://web.example.com', 'redirect' => 'both']);

    Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])])
        ->set("serviceRedirects.{$this->webApp->id}", 'www')
        ->assertDispatched('success')
        ->assertSee('https://www.web.example.com');

    expect($this->webApp->fresh()->redirect)->toBe('www')
        ->and(explode(',', (string) $this->webApp->fresh()->fqdn))
        ->toContain('https://web.example.com')
        ->toContain('https://www.web.example.com');
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

it('saves the explicitly selected service redirect value', function () {
    $this->webApp->update(['fqdn' => 'https://web.example.com', 'redirect' => 'both']);

    Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])])
        ->call('updateServiceRedirect', $this->webApp->id, 'www')
        ->assertDispatched('success')
        ->assertSet('domainRows', fn (array $rows): bool => collect($rows)->pluck('url')->contains('https://www.web.example.com'))
        ->assertSet('domainRows', fn (array $rows): bool => filled(collect($rows)->firstWhere('url', 'https://www.web.example.com')['checked_at'] ?? null))
        ->assertSee('https://www.web.example.com');

    expect($this->webApp->fresh()->redirect)->toBe('www');
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

it('adds only the entered domain when redirects allow both directions', function () {
    $this->webApp->update(['redirect' => 'both']);

    $component = Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])])
        ->set('newServiceApplicationId', $this->webApp->id)
        ->set('newDomain', 'https://web.example.com')
        ->call('addDomain')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    $component->call('pollDnsChecks')
        ->assertSee('DNS skipped');

    expect($this->webApp->fresh()->fqdn)->toBe('https://web.example.com');
});

it('adds a domain to a selected service application', function () {
    Queue::fake();

    Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])])
        ->set('newServiceApplicationId', $this->webApp->id)
        ->set('newDomain', 'https://web.example.com')
        ->call('addDomain')
        ->assertHasNoErrors()
        ->assertDispatched('success', 'Domain added. DNS check started.')
        ->assertSet('domainRows', fn (array $rows): bool => collect($rows)->firstWhere('url', 'https://web.example.com')['dns_status'] === 'checking')
        ->assertSet('domainRows', fn (array $rows): bool => collect($rows)->pluck('url')->contains('https://web.example.com'))
        ->assertSee('https://web.example.com');

    $this->webApp->refresh();
    expect($this->webApp->fqdn)->toBe('https://web.example.com');

    expect($this->webApp->domain_dns_statuses['https://web.example.com']['status'] ?? null)->toBe('checking');

    Queue::assertPushed(CheckDomainDnsJob::class, 1);
});

it('adds a domain when the compose service has an empty environment section', function () {
    $this->service->update([
        'docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n    environment:\n  api:\n    image: node:alpine\n",
    ]);

    Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])])
        ->set('newServiceApplicationId', $this->webApp->id)
        ->set('newDomain', 'https://web.example.com')
        ->call('addDomain')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    expect($this->webApp->fresh()->fqdn)->toBe('https://web.example.com');
});

it('keeps a stable key for the rendered domain list', function () {
    $view = file_get_contents(resource_path('views/livewire/project/service/domains.blade.php'));

    expect($view)
        ->toContain('wire:key="service-domains-list"')
        ->toContain('wire:key="service-domain-rows-{{ $appId }}-{{ md5(serialize($rows->all())) }}"')
        ->not->toContain('md5(serialize($domainRows))');
});

it('provides client-side search for service domains', function () {
    $view = file_get_contents(resource_path('views/livewire/project/service/domains.blade.php'));

    expect($view)
        ->toContain('x-model="domainSearch"')
        ->toContain('class="ml-auto flex flex-wrap items-center gap-2"')
        ->toContain('<div class="relative shrink-0">')
        ->toContain('placeholder="Search services or domains"')
        ->toContain('x-show="matchesDomainSearch(')
        ->toContain('title="No domains found"')
        ->toContain('hasDomainSearchResults(');
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
        ->assertDispatched('success')
        ->assertSet('domainRows', fn (array $rows): bool => collect($rows)->pluck('url')->doesntContain('https://api.example.com'));

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
        ->assertSee('Direction')
        ->assertSee('Search engine indexing')
        ->set('editingDomain', 'https://renamed.example.com')
        ->call('updateDomain')
        ->assertHasNoErrors()
        ->assertDispatched('edit-domain-saved')
        ->assertDispatched('success');

    $this->apiApp->refresh();

    expect($this->apiApp->fqdn)->toBe('https://renamed.example.com')
        ->and($this->apiApp->domain_dns_statuses)->not->toHaveKey('https://api.example.com')
        ->and($this->apiApp->domain_dns_statuses)->toHaveKey('https://renamed.example.com')
        ->and($this->apiApp->domain_dns_statuses['https://renamed.example.com']['status'])->toBe('skipped');
});

it('updates redirect independently from editing a domain', function () {
    Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])])
        ->call('updateServiceRedirect', $this->apiApp->id, 'www')
        ->assertDispatched('success', 'Redirect updated.')
        ->assertNotDispatched('success', 'Domain updated.');
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

    $component = Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])])
        ->call('removeDomain', 0)
        ->set('newServiceApplicationId', $this->apiApp->id)
        ->set('newDomain', 'https://api.example.com')
        ->call('addDomain')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    $component->call('pollDnsChecks');

    $this->apiApp->refresh();

    expect(explode(',', (string) $this->apiApp->fqdn))
        ->toBe(['https://api.example.com'])
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
        ->toBe(['https://api.example.com']);
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

it('polls a queued service dns check and notifies about success', function () {
    $domain = 'https://api.example.com';
    $this->apiApp->update([
        'domain_dns_statuses' => [
            $domain => [
                'status' => 'checking',
                'message' => 'Checking DNS...',
                'expected_ip' => '203.0.113.10',
                'checked_at' => null,
            ],
        ],
    ]);

    $component = Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])])
        ->assertSee('Checking DNS...')
        ->assertSee('wire:poll.2000ms="pollDnsChecks"', false);

    $this->apiApp->update([
        'domain_dns_statuses' => [
            $domain => [
                'status' => 'ok',
                'message' => 'DNS looks correct.',
                'expected_ip' => '203.0.113.10',
                'checked_at' => now()->toIso8601String(),
            ],
        ],
    ]);

    $component->call('pollDnsChecks')
        ->assertSet('domainRows', fn (array $rows): bool => collect($rows)->firstWhere('url', $domain)['dns_status'] === 'ok')
        ->assertDispatched('success', 'DNS is configured correctly for api.example.com.');
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

it('updates search engine indexing from the service domains view', function () {
    Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])])
        ->assertSee('Noindex')
        ->assertSee('Indexable')
        ->assertSee('Search engine indexing')
        ->assertSee('Direction')
        ->assertSee('toggleNoindexDomain', false)
        ->assertSee('updateServiceRedirect', false)
        ->assertSee('wire:ignore', false)
        ->assertDontSee('x-model="localIndexing"', false)
        ->assertDontSee('x-model="localDirection"', false)
        ->assertDontSee('@js(', false)
        ->call('toggleNoindexDomain', $this->apiApp->id, 'https://api.example.com', 'noindex')
        ->assertDispatched('configurationChanged')
        ->assertDispatched('success')
        ->assertSet('service', fn (Service $service): bool => $service->applications
            ->firstWhere('id', $this->apiApp->id)
            ?->isDomainNoindexed('https://api.example.com') === true);

    expect($this->apiApp->refresh()->noindexDomains()->all())
        ->toBe(['https://api.example.com']);

    expect(file_get_contents(resource_path('views/livewire/project/service/partials/domain-table.blade.php')))
        ->not->toContain('<select');
});
