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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    config()->set('app.maintenance.store', 'array');

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

it('marks service application port-only changes as pending configuration', function () {
    $this->webApp->update([
        'fqdn' => 'http://example.com',
        'domain_port_overrides' => ['http://example.com' => 8000],
    ]);
    $this->service->isConfigurationChanged(save: true);

    $this->webApp->update([
        'domain_port_overrides' => ['http://example.com' => 3000],
    ]);

    expect($this->service->refresh()->isConfigurationChanged())->toBeTrue();
});

it('does not mark reordered service application port overrides as changed', function () {
    $this->webApp->update([
        'fqdn' => 'http://one.example.com,http://two.example.com',
        'domain_port_overrides' => [
            'http://one.example.com' => 8000,
            'http://two.example.com' => 3000,
        ],
    ]);
    $this->service->isConfigurationChanged(save: true);

    $this->webApp->update([
        'domain_port_overrides' => [
            'http://two.example.com' => 3000,
            'http://one.example.com' => 8000,
        ],
    ]);

    expect($this->service->refresh()->isConfigurationChanged())->toBeFalse();
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
        ->toContain('src="https://api.example.com/favicon.ico"')
        ->toContain('class="relative size-4 shrink-0"')
        ->toContain('domain-favicon-fallback')
        ->toContain('class="invisible absolute inset-0 size-4 rounded-sm"')
        ->toContain('$el.previousElementSibling.classList.add(\'hidden\')')
        ->toContain('x-on:error="$el.remove()"')
        ->toContain('class="min-w-0 flex-1 truncate text-[13px]')
        ->toContain('class="listbox-trigger"')
        ->toContain('application-settings-section-body is-flush overflow-visible')
        ->toContain('dark:bg-white/[0.04]')
        ->toContain('<span>Domain</span>')
        ->toContain('<span>DNS status</span>')
        ->not->toContain('<span>Last checked</span>')
        ->not->toContain("service-domain-group-{$this->webApp->id}")
        ->and(substr_count($html, '2 domains'))->toBe(1)
        ->and(substr_count($html, '<span>Domain</span>'))->toBe(1)
        ->and(substr_count($html, "id=\"service-domain-group-{$this->apiApp->id}\""))->toBe(1);
});

it('removes consecutive service domains by stable row identity after indexes change', function () {
    $this->apiApp->update([
        'fqdn' => 'https://first.example.com,https://second.example.com,https://third.example.com',
    ]);

    $component = Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])]);

    $component
        ->call('removeDomainByKey', hash('sha256', 'https://first.example.com|'.$this->apiApp->id))
        ->call('removeDomainByKey', hash('sha256', 'https://second.example.com|'.$this->apiApp->id))
        ->assertDispatched('success');

    expect($this->apiApp->fresh()->fqdn)->toBe('https://third.example.com');
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

it('opens address fields and service-wide redirects in the same settings dialog for every domain', function () {
    $domains = ['https://api.example.com', 'https://www.api.example.com', 'https://admin.example.com', 'https://www.admin.example.com'];
    $this->apiApp->update(['fqdn' => implode(',', $domains)]);

    $component = Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])]);

    foreach ($domains as $index => $domain) {
        $html = $component->call('startEdit', $index)
            ->assertSet('editingDomain', $domain)
            ->assertSee('Domain settings')
            ->assertSee('Save')
            ->assertSee('Regenerate hostname')
            ->assertDontSee('Save address')
            ->assertSee('Search engine indexing')
            ->assertSee('www redirect')
            ->assertDontSee('Edit address and port')
            ->html();

        expect(substr_count($html, 'this.$wire.updateServiceRedirect('))->toBe(0);
    }
});

it('uses segmented fields when adding and editing service domains', function () {
    $view = file_get_contents(resource_path('views/livewire/project/service/domains.blade.php'));

    expect($view)
        ->toContain('<x-forms.domain-input id="newDomainParts"')
        ->toContain('<x-forms.domain-input id="editingDomainParts"')
        ->not->toContain('placeholder="https://app.example.com"');
});

it('matches the application domains toolbar heading and top spacing', function () {
    $view = file_get_contents(resource_path('views/livewire/project/service/domains.blade.php'));

    expect($view)
        ->toContain('<div class="flex flex-wrap items-center gap-2">')
        ->toContain('<h2 id="domains-section">Domains</h2>')
        ->not->toContain('<div class="mt-2 flex flex-wrap items-center gap-2">')
        ->not->toContain('<h3>Domains</h3>');
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

it('does not add a single-label hostname as a service domain', function () {
    $this->apiApp->update(['fqdn' => null]);

    Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])])
        ->set('newServiceApplicationId', $this->apiApp->id)
        ->set('newDomainParts.host', 'aaa')
        ->call('addDomain')
        ->assertDispatched('error');

    expect($this->apiApp->fresh()->fqdn)->toBeNull();
});

it('shows dns entries control next to Add', function () {
    Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])])
        ->assertSuccessful()
        ->assertSee('DNS entries')
        ->assertSee('Manual records');
});

it('exposes the dns entries dropdown expanded state', function () {
    $view = file_get_contents(resource_path('views/livewire/project/shared/cloudflare-autoconfigure.blade.php'));

    expect($view)
        ->toContain('x-bind:aria-expanded="dnsEntriesOpen"')
        ->toContain('x-show="dnsEntriesOpen"');
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

it('does not use instance network addresses for service dns entries on a remote server', function () {
    InstanceSettings::get()->update([
        'public_ipv4' => '198.51.100.20',
        'public_ipv6' => '2001:db8::20',
    ]);
    $this->apiApp->update(['fqdn' => 'https://api.example.com']);

    $component = Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])]);

    expect($component->instance()->dnsRecordHints())->toBe([
        [
            'type' => 'A',
            'name' => 'api.example.com',
            'value' => '203.0.113.10',
        ],
    ]);
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
        ->assertSet('domainRows', fn (array $rows): bool => (collect($rows)->firstWhere('url', 'https://www.web.example.com')['dns_status'] ?? null) === 'checking')
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
        ->toContain('wire:key="service-domain-rows-{{ $appId }}"')
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
        ->assertSee('www redirect')
        ->assertSee('Search engine indexing')
        ->set('editingDomainParts.host', 'renamed.example.com')
        ->call('updateDomain')
        ->assertHasNoErrors()
        ->assertDispatched('edit-domain-saved')
        ->assertDispatched('success')
        ->assertNotDispatched('error');

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

it('shows the port warning modal when adding a domain with a non-default port', function () {
    $this->service->update([
        'docker_compose_raw' => <<<'YAML'
services:
  web:
    image: nginx:alpine
    environment:
      - SERVICE_FQDN_WEB_8000
  api:
    image: node:alpine
YAML,
    ]);

    Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])])
        ->set('newServiceApplicationId', $this->webApp->id)
        ->set('newDomainParts.host', 'web.example.com')
        ->set('newDomainParts.port', '3000')
        ->set('newDomainPartsChanged', true)
        ->call('addDomain')
        ->assertSet('showPortWarningModal', true)
        ->assertSet('requiredPort', 8000)
        ->assertSee('Use a different port?');

    expect($this->webApp->fresh()->fqdn)->toBeNull();
});

it('saves a non-default domain port after confirming the warning', function () {
    $this->service->update([
        'docker_compose_raw' => <<<'YAML'
services:
  web:
    image: nginx:alpine
    environment:
      - SERVICE_FQDN_WEB_8000
  api:
    image: node:alpine
YAML,
    ]);

    Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])])
        ->set('newServiceApplicationId', $this->webApp->id)
        ->set('newDomainParts.host', 'web.example.com')
        ->set('newDomainParts.port', '3000')
        ->set('newDomainPartsChanged', true)
        ->call('addDomain')
        ->assertSet('showPortWarningModal', true)
        ->call('confirmRemovePort')
        ->assertSet('showPortWarningModal', false)
        ->assertDispatched('success');

    $this->webApp->refresh();

    expect($this->webApp->fqdn)->toContain('https://web.example.com')
        ->and($this->webApp->domain_port_overrides['https://web.example.com'] ?? null)->toBe(3000);
});

it('clears a service domain port override when saving without a port', function () {
    $this->service->update([
        'docker_compose_raw' => <<<'YAML'
services:
  api:
    image: node:alpine
    environment:
      - SERVICE_FQDN_API_3000
  web:
    image: nginx:alpine
YAML,
    ]);
    $this->apiApp->update([
        'fqdn' => 'https://api.example.com',
        'domain_port_overrides' => [
            'https://api.example.com' => 8080,
        ],
    ]);

    Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])])
        ->call('startEdit', 0)
        ->assertSet('editingDomainParts.port', '8080')
        ->set('editingDomainParts.port', '')
        ->call('updateDomain')
        ->assertHasNoErrors()
        ->assertSee('Internal port 3000')
        ->assertDontSee('Internal port 8080');

    expect($this->apiApp->fresh()->domain_port_overrides ?? [])
        ->not->toHaveKey('https://api.example.com');
});

it('reopens service domain edit with the saved port override', function () {
    $this->apiApp->update([
        'fqdn' => 'https://api.example.com',
        'domain_port_overrides' => [
            'https://api.example.com' => 8080,
        ],
    ]);

    Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])])
        ->assertSet('domainRows.0.url', 'https://api.example.com')
        ->assertSet('domainRows.0.internal_port', 8080)
        ->call('startEdit', 0)
        ->assertSet('editingDomainParts.port', '8080')
        ->assertSet('editingDomainParts.host', 'api.example.com');
});

it('does not show the port warning modal when the domain uses the required port', function () {
    $this->service->update([
        'docker_compose_raw' => <<<'YAML'
services:
  web:
    image: nginx:alpine
    environment:
      - SERVICE_FQDN_WEB_8000
  api:
    image: node:alpine
YAML,
    ]);

    Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])])
        ->set('newServiceApplicationId', $this->webApp->id)
        ->set('newDomainParts.host', 'web.example.com')
        ->set('newDomainParts.port', '8000')
        ->set('newDomainPartsChanged', true)
        ->call('addDomain')
        ->assertSet('showPortWarningModal', false)
        ->assertDispatched('success');
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
        ->assertSet('forceSaveDomains', true)
        ->assertSee('Use a different port?')
        ->assertSee('Keep required port')
        ->assertSee('Use this port anyway');

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
        ->assertCount('domainRows', 2)
        ->assertSet('domainRows.1.url', 'https://healthy.example.com');
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
        ->assertSee('DNS matches')
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
        ->assertDontSee('Check all DNS')
        ->assertDontSee('aria-label="Settings for', false)
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
        ->call('startEdit', 0)
        ->assertSee('Noindex')
        ->assertSee('Indexable')
        ->assertSee('Search engine indexing')
        ->assertSee('www redirect')
        ->assertSee('editingIndexing', false)
        ->assertDontSee('toggleNoindexDomain', false)
        ->set('editingIndexing', 'noindex')
        ->call('updateDomain')
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

it('regenerates a service application domain only when the modal is saved', function () {
    $component = Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])])
        ->call('startEdit', 0)
        ->set('editingIndexing', 'noindex')
        ->call('regenerateEditingDomain');

    $generatedHost = $component->get('editingDomainParts')['host'];

    expect($generatedHost)->not->toBe('api.example.com')
        ->and($this->apiApp->fresh()->fqdn)->toBe('https://api.example.com');

    $component->call('updateDomain')->assertHasNoErrors();

    expect($this->apiApp->fresh()->fqdn)->toBe("https://{$generatedHost}")
        ->and($this->apiApp->fresh()->noindexDomains()->all())->toBe(["https://{$generatedHost}"]);
});

it('starts a dns check after a manually edited service domain is saved', function () {
    Queue::fake();
    $settings = InstanceSettings::get();
    $settings->is_dns_validation_enabled = true;
    $settings->save();
    $this->apiApp->update(['fqdn' => 'https://api.example.com:81']);

    Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])])
        ->call('startEdit', 0)
        ->assertSet('editingDomainParts.port', '81')
        ->set('editingDomainParts.host', 'manual-service.example.com')
        ->call('updateDomain')
        ->assertSet('domainRows', fn (array $rows): bool => collect($rows)->contains(
            fn (array $row): bool => $row['url'] === 'https://manual-service.example.com' && $row['dns_status'] === 'checking'
        ));

    expect($this->apiApp->fresh()->domain_dns_statuses['https://manual-service.example.com']['status'] ?? null)->toBe('checking');
    Queue::assertPushed(CheckDomainDnsJob::class, fn (CheckDomainDnsJob $job): bool => $job->url === 'https://manual-service.example.com'
        && $job->statusKey === 'https://manual-service.example.com');
});

it('does not start a dns check when only service domain settings change', function () {
    Queue::fake();

    Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])])
        ->call('startEdit', 0)
        ->set('editingIndexing', 'noindex')
        ->call('updateDomain')
        ->assertHasNoErrors();

    Queue::assertNotPushed(CheckDomainDnsJob::class);
});

it('checks the counterpart added by a service domain redirect change', function () {
    Queue::fake();

    Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])])
        ->call('startEdit', 0)
        ->set('editingRedirect', 'www')
        ->call('updateDomain')
        ->assertHasNoErrors();

    expect(explode(',', (string) $this->apiApp->fresh()->fqdn))->toContain('https://www.api.example.com');
    Queue::assertPushed(CheckDomainDnsJob::class, 1);
    Queue::assertPushed(CheckDomainDnsJob::class, fn (CheckDomainDnsJob $job): bool => $job->url === 'https://www.api.example.com');
});

it('keeps noindex domains when normalizing a custom service domain port', function () {
    $this->apiApp->update([
        'fqdn' => 'https://api.example.com:8080',
        'noindex_domains' => ['https://api.example.com:8080'],
    ]);

    expect($this->apiApp->refresh())
        ->fqdn->toBe('https://api.example.com')
        ->noindex_domains->toBe(['https://api.example.com'])
        ->and($this->apiApp->domain_port_overrides)
        ->toBe(['https://api.example.com' => 8080]);
});

it('shows an inherited internal port badge from the coolify service env port', function () {
    $this->service->update([
        'docker_compose_raw' => "services:\n  api:\n    image: node:alpine\n    environment:\n      - SERVICE_FQDN_API_3000\n",
    ]);
    $this->apiApp->update([
        'fqdn' => 'https://api.example.com',
        'domain_port_overrides' => null,
    ]);

    Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])])
        ->assertSet('domainRows.0.internal_port', 3000)
        ->assertSet('domainRows.0.has_port_override', false)
        ->set('dnsValidationEnabled', false)
        ->call('startEdit', 0)
        ->assertSet('editingDomainParts.port', '3000')
        ->call('updateDomain')
        ->assertHasNoErrors()
        ->assertSee('Internal port 3000')
        ->assertSee('Inherited from the Coolify service port', false)
        ->assertDontSee('No internal port');

    expect($this->apiApp->fresh())
        ->fqdn->toBe('https://api.example.com')
        ->domain_port_overrides->toBeNull();
});

it('shows a custom internal port badge for a service domain override', function () {
    $this->service->update([
        'docker_compose_raw' => "services:\n  api:\n    image: node:alpine\n    environment:\n      - SERVICE_FQDN_API_3000\n",
    ]);
    $this->apiApp->update([
        'fqdn' => 'https://api.example.com',
        'domain_port_overrides' => [
            'https://api.example.com' => 8080,
        ],
    ]);

    Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])])
        ->assertSet('domainRows.0.internal_port', 8080)
        ->assertSet('domainRows.0.has_port_override', true)
        ->assertSee('Internal port 8080')
        ->assertSee('Custom internal port for this domain', false)
        ->assertDontSee('Internal port 3000');
});

it('does not show an internal port badge when the service has no env port', function () {
    $this->apiApp->update([
        'fqdn' => 'https://api.example.com',
        'domain_port_overrides' => null,
    ]);

    Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])])
        ->assertSet('domainRows.0.internal_port', null)
        ->assertDontSee('No internal port')
        ->assertDontSee('Internal port ')
        ->assertDontSee('table-badge-danger', false);
});

it('prioritizes public addresses and moves domain configuration behind settings', function () {
    $this->apiApp->update(['fqdn' => 'https://api.example.com:8080']);

    $html = Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])])
        ->assertSee('Check all DNS')
        ->assertSee('Add domain')
        ->assertSee('Domain settings')
        ->call('startEdit', 0)
        ->assertDontSee('Indexing and redirect changes save automatically.')
        ->assertSee('Internal port 8080')
        ->assertSee('Both www and non-www')
        ->assertSee('Search indexing allowed')
        ->assertDontSee('Manage domains and www/non-www redirects')
        ->html();

    expect($html)->toContain('title="https://api.example.com"')
        ->not->toContain('title="https://api.example.com:8080"');
});

it('distinguishes unchecked domains from dns checks in progress', function () {
    InstanceSettings::find(0)->update(['is_dns_validation_enabled' => true]);
    Cache::forget('instance_settings');

    Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])])
        ->assertSee('Not checked')
        ->assertDontSee('DNS pending');
});

it('keeps the edited domain selected when settings refresh and reorder rows', function () {
    $component = Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])])
        ->call('startEdit', 0);

    $this->webApp->update([
        'fqdn' => 'https://broken.example.com',
        'domain_dns_statuses' => [
            'https://broken.example.com' => ['status' => 'failed', 'message' => 'Mismatch'],
        ],
    ]);

    $component->call('refreshDomains')
        ->assertSet('editingIndex', 1)
        ->set('editingDomainParts.host', 'renamed.example.com')
        ->call('updateDomain')
        ->assertHasNoErrors();

    expect($this->apiApp->fresh()->fqdn)->toBe('https://renamed.example.com')
        ->and($this->webApp->fresh()->fqdn)->toBe('https://broken.example.com');
});

it('renders compact icon-only domain actions with accessible labels', function () {
    $html = Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])])->html();
    $document = new DOMDocument;
    @$document->loadHTML($html);
    $xpath = new DOMXPath($document);

    foreach (['Check DNS', 'Settings for https://api.example.com', 'Remove domain'] as $label) {
        $buttons = $xpath->query('//button[@aria-label="'.$label.'"]');
        expect($buttons->length)->toBe(1);
        $button = $buttons->item(0);
        expect(trim($button->textContent))->toBe('')
            ->and($button->getAttribute('class'))->toContain('icon-button')
            ->and($button->getAttribute('title'))->not->toBe('');
    }

    expect($html)->not->toContain('aria-label="More actions for');
});

it('uses the shared mobile domain summary layout', function () {
    $view = file_get_contents(resource_path('views/livewire/project/service/partials/domain-table.blade.php'));

    expect($view)
        ->toContain('service-domain-mobile-summary')
        ->toContain('Domain routing summary')
        ->toContain('No redirects')
        ->toContain('Noindex');
});

it('uses explicit modal actions for pending domain edits', function () {
    $view = file_get_contents(resource_path('views/livewire/project/service/domains.blade.php'));

    expect($view)->not->toContain('<x-unsaved-bar action="updateDomain"')
        ->toContain('wire:click="regenerateEditingDomain"')
        ->toContain('wire:click="updateDomain"')
        ->toContain('Save');
});

it('opens service domain settings from browser data and shows a dns spinner', function () {
    $view = file_get_contents(resource_path('views/livewire/project/service/partials/domain-table.blade.php'));

    expect($view)
        ->not->toContain('wire:click="startEdit(')
        ->toContain('@click="openEditDomain(')
        ->toContain('<x-loading compact aria-label="Checking DNS"')
        ->not->toContain('<x-loading-on-button wire:loading.delay');
});

it('uses the dns badge as progress for single and all service checks', function (string $action, array $parameters) {
    Queue::fake();

    Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])])
        ->call($action, ...$parameters)
        ->assertSet('domainRows.0.dns_status', 'checking')
        ->assertSee('Checking DNS...')
        ->assertSeeHtml('loading-indicator');

    Queue::assertPushed(CheckDomainDnsJob::class);
})->with([
    'single domain' => ['checkDomainDns', [0]],
    'all domains' => ['checkAllDns', []],
]);

it('inherits the counterpart internal port when enabling redirects without a port warning', function (?int $override, string $redirect) {
    $this->service->update([
        'docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n    environment:\n      - SERVICE_URL_WEB_80\n  api:\n    image: node:alpine\n",
    ]);
    $host = $redirect === 'www' ? 'web.example.com' : 'www.web.example.com';
    $counterpart = $redirect === 'www' ? 'www.web.example.com' : 'web.example.com';
    $url = "https://{$host}/blog";
    $pairedUrl = "https://{$counterpart}/blog";
    $this->webApp->update([
        'fqdn' => $url,
        'redirect' => 'both',
        'domain_port_overrides' => $override === null ? null : [$url => $override],
    ]);

    Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])])
        ->call('updateServiceRedirect', $this->webApp->id, $redirect)
        ->assertHasNoErrors()
        ->assertSet('showPortWarningModal', false)
        ->assertSet('pendingAction', null)
        ->assertDispatched('success', 'Redirect updated.')
        ->call('refreshDomains')
        ->assertSet("serviceRedirects.{$this->webApp->id}", $redirect);

    $this->webApp->refresh();
    expect($this->webApp->redirect)->toBe($redirect)
        ->and($this->webApp->fqdn)->toContain($pairedUrl)
        ->and($this->webApp->domain_port_overrides[$pairedUrl] ?? $this->webApp->getRequiredPort())->toBe($override ?? 80)
        ->and($this->webApp->domain_port_overrides[$url] ?? null)->toBe($override);
})->with([null, 80, 8080])->with(['www', 'non-www']);

it('still warns and allows cancellation when manually adding a different port', function () {
    $this->service->update([
        'docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n    environment:\n      - SERVICE_FQDN_WEB_8000\n  api:\n    image: node:alpine\n",
    ]);

    Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])])
        ->set('newServiceApplicationId', $this->webApp->id)
        ->set('newDomain', 'https://web.example.com:3000')
        ->call('addDomain')
        ->assertSet('showPortWarningModal', true)
        ->call('cancelRemovePort')
        ->assertSet('showPortWarningModal', false)
        ->assertSet('pendingAction', null)
        ->call('addDomain')
        ->assertSet('showPortWarningModal', true);

    expect($this->webApp->fresh()->fqdn)->toBeNull();
});

it('still checks domain conflicts when inheriting a redirect counterpart port', function () {
    $this->webApp->update([
        'fqdn' => 'https://example.com',
        'redirect' => 'both',
        'domain_port_overrides' => ['https://example.com' => 8080],
    ]);
    $this->apiApp->update(['fqdn' => 'https://www.example.com']);

    $component = Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])])
        ->call('updateServiceRedirect', $this->webApp->id, 'www')
        ->assertSet('showDomainConflictModal', true)
        ->assertSet('showPortWarningModal', false);

    expect($this->webApp->fresh()->redirect)->toBe('both');

    $component->call('refreshDomains')
        ->call('confirmDomainUsage')
        ->assertSet('showDomainConflictModal', false)
        ->assertSet('showPortWarningModal', false)
        ->assertDispatched('success', 'Redirect updated.');

    expect($this->webApp->fresh()->redirect)->toBe('www')
        ->and($this->webApp->fresh()->domain_port_overrides['https://www.example.com'])->toBe(8080);
});

it('renders domain settings in compact columns instead of a second summary line', function () {
    $html = Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])])->html();
    foreach (['Protocol redirect', 'Domain redirect', 'Internal port', 'Search indexing'] as $heading) {
        expect($html)->toContain('<span>'.$heading.'</span>');
    }
    $view = file_get_contents(resource_path('views/livewire/project/service/partials/domain-table.blade.php'));
    expect($view)->toContain('service-domain-detail')
        ->not->toContain('gap-x-3 gap-y-1');
});

it('renders each domain table header below its service heading', function () {
    $view = file_get_contents(resource_path('views/livewire/project/service/domains.blade.php'));
    $serviceHeadingPosition = strpos($view, 'service-domain-group-{{ $appId }}');
    $domainTablePosition = strpos($view, "'showHeader' => true");

    expect($view)
        ->not->toContain('<div class="data-table-header service-domains-overview-grid">')
        ->and($serviceHeadingPosition)->not->toBeFalse()
        ->and($domainTablePosition)->not->toBeFalse()
        ->and($domainTablePosition)->toBeGreaterThan($serviceHeadingPosition);
});

it('renders each service domain group as a separate card', function () {
    $view = file_get_contents(resource_path('views/livewire/project/service/domains.blade.php'));

    expect($view)
        ->toContain('class="flex flex-col gap-3"')
        ->toContain('class="application-settings-section-body is-flush overflow-visible"')
        ->not->toContain('class="border-b border-neutral-200 last:border-b-0 dark:border-white/10"');
});

it('lays out the domain settings dropdowns in responsive columns', function () {
    $view = file_get_contents(resource_path('views/livewire/project/service/domains.blade.php'));
    expect($view)->toContain('mt-4 grid grid-cols-1 gap-4 border-t border-neutral-200 pt-4 sm:grid-cols-2')
        ->toContain('flex flex-wrap items-center justify-between gap-2');
});
