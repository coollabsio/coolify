<?php

use App\Livewire\Project\Application\Domains;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $keyId = DB::table('private_keys')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'name' => 'Test Key',
        'private_key' => 'test-key',
        'team_id' => $this->team->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $keyId,
        'ip' => '203.0.113.10',
    ]);

    $this->server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
    ]);

    StandaloneDocker::withoutEvents(function () {
        $this->destination = StandaloneDocker::firstOrCreate(
            ['server_id' => $this->server->id, 'network' => 'coolify'],
            ['uuid' => (string) Str::uuid(), 'name' => 'test-docker']
        );
    });

    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);

    $this->application = Application::factory()->create([
        'uuid' => (string) Str::uuid(),
        'name' => 'Domains App',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'fqdn' => null,
        'redirect' => 'both',
        'build_pack' => 'nixpacks',
    ]);

    $this->application->settings()->update([
        'is_container_label_readonly_enabled' => true,
    ]);
});

it('uses safe domain validation rules on the domains form', function () {
    $component = new Domains;
    $method = new ReflectionMethod($component, 'rules');
    $rules = $method->invoke($component);

    $validator = validator([
        'newDomain' => 'http://$(whoami).example.com',
    ], [
        'newDomain' => $rules['newDomain'],
    ]);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('newDomain'))->toBeTrue();
});

it('lists existing domains as individual rows', function () {
    $this->application->update([
        'fqdn' => 'https://app.example.com,https://www.example.com',
    ]);

    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->assertSuccessful()
        ->assertSet('domainRows.0.url', 'https://app.example.com')
        ->assertSet('domainRows.1.url', 'https://www.example.com')
        ->assertSee('https://app.example.com')
        ->assertSee('https://www.example.com');
});

it('shows dns entries control next to Add', function () {
    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->assertSuccessful()
        ->assertSee('DNS entries')
        ->assertSee('Manual records');
});

it('lists dns entries for domains that still need dns and omits working configured hosts', function () {
    $this->application->update([
        'fqdn' => 'https://app.example.com,https://www.example.com,https://api.example.com',
        'domain_dns_statuses' => [
            'https://app.example.com' => [
                'status' => 'ok',
                'message' => 'OK',
                'expected_ip' => '203.0.113.10',
                'checked_at' => now()->toIso8601String(),
            ],
            'https://api.example.com' => [
                'status' => 'failed',
                'message' => 'Mismatch',
                'expected_ip' => '203.0.113.10',
                'checked_at' => now()->toIso8601String(),
            ],
        ],
    ]);

    $component = Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->call('openDnsRecordsModal')
        ->assertSet('showDnsRecordsModal', true)
        ->assertSee('Recheck');

    $names = collect($component->instance()->dnsRecordHints())->pluck('name')->all();

    expect($names)
        ->toContain('api.example.com')
        ->not->toContain('app.example.com');
});

it('shows cloudflare domain connect only on cloud with a key', function () {
    config([
        'constants.coolify.self_hosted' => false,
        'services.domain_connect.private_key' => null,
    ]);

    $key = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    openssl_pkey_export($key, $privateKey);

    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->assertDontSee('Cloudflare');

    config(['services.domain_connect.private_key' => $privateKey]);

    $this->application->update(['fqdn' => 'https://app.example.com']);

    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->assertSee('Cloudflare')
        ->call('openCloudflareAutoconfigureModal')
        ->assertSet('showCloudflareAutoconfigureModal', true)
        ->assertSee('app.example.com')
        ->assertDontSee('Domain or hostname')
        ->call('applyCloudflareAutoconfigure')
        ->assertHasNoErrors()
        ->assertSet('showCloudflareAutoconfigureModal', false);
});

it('adds a domain to the application', function () {
    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->assertSee('+ Add')
        ->set('newDomain', 'https://app.example.com')
        ->call('addDomain')
        ->assertHasNoErrors()
        ->assertSet('addDomainDnsFailed', false)
        ->assertDispatched('success')
        ->assertDispatched('close-modal');

    $this->application->refresh();

    expect($this->application->fqdn)->toBe('https://app.example.com');
});

it('adds multiple domains without replacing existing ones', function () {
    $this->application->update([
        'fqdn' => 'https://app.example.com',
    ]);

    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->set('newDomain', 'https://api.example.com')
        ->call('addDomain')
        ->assertHasNoErrors();

    $this->application->refresh();

    expect(explode(',', (string) $this->application->fqdn))
        ->toContain('https://app.example.com')
        ->toContain('https://api.example.com');
});

it('blocks adding a domain with bad dns until the user continues', function () {
    $settings = InstanceSettings::get();
    $settings->is_dns_validation_enabled = true;
    $settings->save();

    $component = Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->set('newDomain', 'https://this-domain-should-not-resolve-for-coolify-tests.invalid')
        ->call('addDomain')
        ->assertSet('addDomainDnsFailed', true)
        ->assertSee('DNS validation failed');

    $this->application->refresh();
    expect($this->application->fqdn)->toBeNull();

    $component->call('confirmAddDomainDespiteDns')
        ->assertSet('addDomainDnsFailed', false)
        ->assertDispatched('success')
        ->assertDispatched('close-modal');

    $this->application->refresh();
    expect($this->application->fqdn)->toBe('https://this-domain-should-not-resolve-for-coolify-tests.invalid');
});

it('resets the dns gate when the domain input changes', function () {
    $settings = InstanceSettings::get();
    $settings->is_dns_validation_enabled = true;
    $settings->save();

    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->set('newDomain', 'https://this-domain-should-not-resolve-for-coolify-tests.invalid')
        ->call('addDomain')
        ->assertSet('addDomainDnsFailed', true)
        ->set('newDomain', 'https://another.example.com')
        ->assertSet('addDomainDnsFailed', false)
        ->assertSet('forceSaveDns', false);
});

it('updates a domain in place via modal', function () {
    $this->application->update([
        'fqdn' => 'https://old.example.com,https://keep.example.com',
    ]);

    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->call('startEdit', 0)
        ->assertSet('showEditDomainModal', true)
        ->assertSet('editingDomain', 'https://old.example.com')
        ->set('editingDomain', 'https://new.example.com')
        ->call('updateDomain')
        ->assertHasNoErrors()
        ->assertSet('showEditDomainModal', false)
        ->assertDispatched('success');

    $this->application->refresh();

    expect($this->application->fqdn)->toBe('https://new.example.com,https://keep.example.com');
});

it('blocks editing a domain with bad dns until the user continues', function () {
    $settings = InstanceSettings::get();
    $settings->is_dns_validation_enabled = true;
    $settings->save();

    $this->application->update([
        'fqdn' => 'https://old.example.com',
    ]);

    $component = Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->call('startEdit', 0)
        ->set('editingDomain', 'https://this-domain-should-not-resolve-for-coolify-tests.invalid')
        ->call('updateDomain')
        ->assertSet('editDomainDnsFailed', true)
        ->assertSet('showEditDomainModal', true)
        ->assertSee('DNS validation failed');

    $this->application->refresh();
    expect($this->application->fqdn)->toBe('https://old.example.com');

    $component->call('confirmUpdateDomainDespiteDns')
        ->assertSet('editDomainDnsFailed', false)
        ->assertSet('showEditDomainModal', false)
        ->assertDispatched('success');

    $this->application->refresh();
    expect($this->application->fqdn)->toBe('https://this-domain-should-not-resolve-for-coolify-tests.invalid');
});

it('removes a domain', function () {
    $this->application->update([
        'fqdn' => 'https://app.example.com,https://www.example.com',
    ]);

    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->call('removeDomain', 0)
        ->assertDispatched('success');

    $this->application->refresh();

    expect($this->application->fqdn)->toBe('https://www.example.com');
});

it('does not revalidate dns on remaining domains when removing one', function () {
    $settings = InstanceSettings::get();
    $settings->is_dns_validation_enabled = true;
    $settings->save();

    $this->application->update([
        'fqdn' => 'https://keep-this-should-not-resolve-for-coolify-tests.invalid,https://remove-this-should-not-resolve-for-coolify-tests.invalid',
    ]);

    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->call('removeDomain', 1)
        ->assertDispatched('success')
        ->assertNotDispatched('error');

    $this->application->refresh();

    expect($this->application->fqdn)->toBe('https://keep-this-should-not-resolve-for-coolify-tests.invalid');
});

it('sets redirect direction when www domain exists', function () {
    $this->application->update([
        'fqdn' => 'https://example.com,https://www.example.com',
        'redirect' => 'both',
    ]);

    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->set('redirect', 'www')
        ->call('setRedirect')
        ->assertDispatched('success');

    $this->application->refresh();

    expect($this->application->redirect)->toBe('www');
});

it('auto-adds missing www counterpart as a normal domain when setting www redirect', function () {
    $this->application->update([
        'fqdn' => 'https://example.com',
        'redirect' => 'both',
    ]);

    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->set('redirect', 'www')
        ->call('setRedirect')
        ->assertDispatched('success')
        ->assertSet('domainRows.0.is_suggested', false)
        ->assertSet('domainRows.1.is_suggested', false)
        ->assertSet('domainRows.0.url', 'https://example.com')
        ->assertSet('domainRows.1.url', 'https://www.example.com');

    $this->application->refresh();

    expect($this->application->redirect)->toBe('www')
        ->and(explode(',', (string) $this->application->fqdn))
        ->toContain('https://example.com')
        ->toContain('https://www.example.com');
});

it('auto-adds missing non-www counterpart as a normal domain when setting non-www redirect', function () {
    $this->application->update([
        'fqdn' => 'https://www.example.com',
        'redirect' => 'both',
    ]);

    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->set('redirect', 'non-www')
        ->call('setRedirect')
        ->assertDispatched('success');

    $this->application->refresh();

    expect($this->application->redirect)->toBe('non-www')
        ->and(explode(',', (string) $this->application->fqdn))
        ->toContain('https://www.example.com')
        ->toContain('https://example.com');
});

it('saves redirect after confirming a conflict for an auto-added www pair', function () {
    Application::factory()->create([
        'uuid' => (string) Str::uuid(),
        'name' => 'WWW Taken App',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'fqdn' => 'https://www.example.com',
        'build_pack' => 'nixpacks',
    ]);

    $this->application->update([
        'fqdn' => 'https://example.com',
        'redirect' => 'both',
    ]);

    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->set('redirect', 'www')
        ->call('setRedirect')
        ->assertSet('showDomainConflictModal', true)
        ->assertSet('pendingAction', 'redirect')
        ->call('confirmDomainUsage')
        ->assertSet('showDomainConflictModal', false)
        ->assertSet('pendingAction', null)
        ->assertDispatched('success');

    $this->application->refresh();

    expect($this->application->redirect)->toBe('www')
        ->and(explode(',', (string) $this->application->fqdn))
        ->toContain('https://example.com')
        ->toContain('https://www.example.com');
});

it('marks dns status as skipped when dns validation is disabled', function () {
    $this->application->update([
        'fqdn' => 'https://app.example.com',
    ]);

    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->call('checkAllDns')
        ->assertSet('domainRows.0.dns_status', 'skipped');

    $this->application->refresh();
    $statuses = $this->application->domain_dns_statuses;
    expect($statuses)->toBeArray()
        ->and($statuses['https://app.example.com']['status'] ?? null)->toBe('skipped');
});

it('prevents members from running dns check actions', function (string $action, array $parameters) {
    $this->team->members()->updateExistingPivot($this->user->id, ['role' => 'member']);
    $this->actingAs($this->user->fresh());
    $this->application->update([
        'fqdn' => 'https://app.example.com',
        'domain_dns_statuses' => null,
    ]);

    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->call($action, ...$parameters)
        ->assertForbidden();

    expect($this->application->fresh()->domain_dns_statuses)->toBeNull();
})->with([
    'all domains' => ['checkAllDns', []],
    'single domain' => ['checkDomainDns', [0]],
]);

it('hides dns check buttons from members', function () {
    $this->team->members()->updateExistingPivot($this->user->id, ['role' => 'member']);
    $this->actingAs($this->user->fresh());
    $this->application->update(['fqdn' => 'https://app.example.com']);

    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->assertDontSee('Recheck DNS')
        ->assertDontSee('Check DNS');
});

it('loads persisted dns status on page load', function () {
    $this->application->update([
        'fqdn' => 'https://app.example.com',
        'domain_dns_statuses' => [
            'https://app.example.com' => [
                'status' => 'failed',
                'message' => 'DNS does not point to 203.0.113.10.',
                'expected_ip' => '203.0.113.10',
                'checked_at' => now()->subHour()->toIso8601String(),
            ],
        ],
    ]);

    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->assertSet('domainRows.0.dns_status', 'failed')
        ->assertSet('domainRows.0.dns_message', 'DNS does not point to 203.0.113.10.')
        ->assertSee('DNS does not point to 203.0.113.10.');
});

it('hides dns message text when dns status is ok', function () {
    $this->application->update([
        'fqdn' => 'https://app.example.com',
        'domain_dns_statuses' => [
            'https://app.example.com' => [
                'status' => 'ok',
                'message' => 'DNS points to 203.0.113.10 (or Cloudflare).',
                'expected_ip' => '203.0.113.10',
                'checked_at' => now()->toIso8601String(),
            ],
        ],
    ]);

    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->assertSet('domainRows.0.dns_status', 'ok')
        ->assertSee('DNS OK')
        ->assertDontSee('DNS points to 203.0.113.10')
        ->assertDontSee('Last checked');
});

it('persists dns status after checking a domain', function () {
    $settings = InstanceSettings::get();
    $settings->is_dns_validation_enabled = true;
    $settings->save();

    $domain = 'https://this-domain-should-not-resolve-for-coolify-tests.invalid';
    $this->application->update([
        'fqdn' => $domain,
    ]);

    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->call('checkDomainDns', 0)
        ->assertSet('domainRows.0.dns_status', 'failed');

    $this->application->refresh();
    $entry = $this->application->domain_dns_statuses[$domain] ?? null;

    expect($entry)->toBeArray()
        ->and($entry['status'] ?? null)->toBe('failed')
        ->and($entry['checked_at'] ?? null)->not->toBeNull();
});

it('resolves hostname server addresses to a real ip for dns messages', function () {
    $this->server->update(['ip' => 'localhost']);
    $this->application->update([
        'fqdn' => 'https://app.example.com',
    ]);

    // Enable DNS validation so checkAllDns produces ok/failed messages (not skipped).
    // Update the once()-cached model instance so subsequent instanceSettings() calls see it.
    $settings = InstanceSettings::get();
    $settings->is_dns_validation_enabled = true;
    $settings->save();

    $component = Livewire::test(Domains::class, ['application' => $this->application->fresh()]);

    $resolvedIp = $component->get('serverIp');

    expect($component->get('serverIpConfigured'))->toBe('localhost')
        ->and($resolvedIp)->not->toBe('localhost')
        ->and(filter_var($resolvedIp, FILTER_VALIDATE_IP))->not->toBeFalse()
        ->and($component->get('domainRows.0.expected_ip'))->toBe($resolvedIp);

    $component->call('checkAllDns');

    $message = $component->get('domainRows.0.dns_message');
    $recordType = dnsRecordTypeForIp($resolvedIp);

    // Failed checks show short "A record → ip" guidance; ok checks mention the hostname label.
    if ($component->get('domainRows.0.dns_status') === 'failed') {
        expect($message)->toBe("{$recordType} record → {$resolvedIp}")
            ->and($message)->not->toContain('CNAME');
    } else {
        expect($message)->toContain($resolvedIp)
            ->and($message)->toContain('localhost');
    }
});

it('uses short aaaa guidance when the server ip is ipv6', function () {
    $this->server->update(['ip' => '2001:db8::10']);
    $this->application->update([
        'fqdn' => 'https://this-domain-should-not-resolve-for-coolify-aaaa.invalid',
    ]);

    $settings = InstanceSettings::get();
    $settings->is_dns_validation_enabled = true;
    $settings->save();

    $component = Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->call('checkDomainDns', 0);

    expect($component->get('serverIp'))->toBe('2001:db8::10')
        ->and($component->get('domainRows.0.dns_message'))->toBe('AAAA record → 2001:db8::10');
});

it('uses short a-record guidance for compose applications', function () {
    $this->application->update([
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n",
        'docker_compose_domains' => json_encode([
            'web' => ['domain' => 'https://compose-dns-test.invalid'],
        ]),
        'fqdn' => null,
    ]);

    $settings = InstanceSettings::get();
    $settings->is_dns_validation_enabled = true;
    $settings->save();

    $this->server->update(['ip' => '172.16.0.3']);

    $component = Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->call('checkDomainDns', 0);

    expect($component->get('domainRows.0.dns_message'))->toBe('A record → 172.16.0.3')
        ->and($component->get('domainRows.0.dns_status'))->toBe('failed');
});

it('normalizes domains before saving', function () {
    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->set('newDomain', 'HTTPS://App.Example.COM/Path')
        ->call('addDomain')
        ->assertHasNoErrors();

    $this->application->refresh();

    expect($this->application->fqdn)->toBe(ValidationPatterns::normalizeApplicationDomains('HTTPS://App.Example.COM/Path'));
});

it('shows the missing www counterpart as a suggested domain row', function () {
    $this->application->update([
        'fqdn' => 'https://example.com',
        'redirect' => 'both',
    ]);

    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->assertSet('domainRows.0.url', 'https://example.com')
        ->assertSet('domainRows.0.is_suggested', false)
        ->assertSet('domainRows.1.url', 'https://www.example.com')
        ->assertSet('domainRows.1.is_suggested', true)
        ->assertSee('Suggested www')
        ->assertSee('Add')
        ->assertSee('https://www.example.com');
});

it('does not change suggested domain labels or persist until Set Direction saves', function () {
    $this->application->update([
        'fqdn' => 'https://example.com',
        'redirect' => 'both',
    ]);

    $component = Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->assertSet('domainRows.1.suggestion_label', 'Suggested www')
        ->assertSet('domainRows.1.suggestion_role', 'pair')
        ->set('redirect', 'www')
        // Dropdown alone must not rebuild suggestions or persist redirect.
        ->assertSet('domainRows.1.suggestion_label', 'Suggested www')
        ->assertSet('domainRows.1.suggestion_role', 'pair');

    expect($this->application->fresh()->redirect)->toBe('both');

    $component
        ->call('setRedirect')
        ->assertDispatched('success');

    $this->application->refresh();
    expect($this->application->redirect)->toBe('www')
        ->and(explode(',', (string) $this->application->fqdn))
        ->toContain('https://example.com')
        ->toContain('https://www.example.com');
});

it('does not persist compose service redirect until Set Direction is called', function () {
    $this->application->update([
        'build_pack' => 'dockercompose',
        'fqdn' => null,
        'docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n",
        'docker_compose_domains' => json_encode([
            'web' => ['domain' => 'https://web.example.com', 'redirect' => 'both'],
        ]),
    ]);

    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->set('isCompose', true)
        ->set('composeServices', ['web'])
        ->set('serviceRedirects.web', 'www');

    $this->application->refresh();
    $domains = json_decode($this->application->docker_compose_domains, true);

    expect(data_get($domains, 'web.redirect'))->toBe('both');
});

it('sets redirect for compose services whose names contain dots', function () {
    $this->application->update([
        'build_pack' => 'dockercompose',
        'fqdn' => null,
        'docker_compose_raw' => "services:\n  api:\n    image: node:alpine\n  api.test:\n    image: nginx:alpine\n",
        'docker_compose_domains' => json_encode([
            'api' => ['domain' => 'https://api.example.com', 'redirect' => 'both'],
            'api.test' => ['domain' => 'https://api-test.example.com', 'redirect' => 'both'],
        ]),
    ]);

    $wireKey = str_replace('.', '__dot__', 'api.test');

    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->set('isCompose', true)
        ->set('composeServices', ['api', 'api.test'])
        ->set("serviceRedirects.{$wireKey}", 'non-www')
        ->call('setServiceRedirect', 'api.test')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    $this->application->refresh();
    $domains = json_decode($this->application->docker_compose_domains, true);

    expect($domains['api.test']['redirect'] ?? null)->toBe('non-www')
        ->and($domains['api']['redirect'] ?? null)->toBe('both')
        // api remains a string-valued sibling, not nested by the dotted service binding
        ->and($domains['api']['domain'] ?? null)->toBe('https://api.example.com');
});

it('accepts service names wrapped in quotes from modal-confirmation', function () {
    $this->application->update([
        'build_pack' => 'dockercompose',
        'fqdn' => null,
        'docker_compose_raw' => "services:\n  api:\n    image: nginx:alpine\n",
        'docker_compose_domains' => json_encode([
            'api' => ['domain' => 'https://api.example.com', 'redirect' => 'both'],
        ]),
    ]);

    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->set('isCompose', true)
        ->set('composeServices', ['api'])
        ->set('serviceRedirects.api', 'www')
        // modal-confirmation historically passed quoted string params + empty password
        ->call('setServiceRedirect', '"api"', '')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    $this->application->refresh();
    $domains = json_decode($this->application->docker_compose_domains, true);

    expect($domains['api']['redirect'] ?? null)->toBe('www')
        ->and(explode(',', (string) ($domains['api']['domain'] ?? '')))
        ->toContain('https://api.example.com')
        ->toContain('https://www.api.example.com');
});

it('auto-adds www pair for compose sslip domains when setting redirect', function () {
    $this->application->update([
        'build_pack' => 'dockercompose',
        'fqdn' => null,
        'docker_compose_raw' => "services:\n  api:\n    image: nginx:alpine\n",
        'docker_compose_domains' => json_encode([
            'api' => [
                'domain' => 'http://api-docker-compose.127.0.0.1.sslip.io',
                'redirect' => 'both',
            ],
        ]),
    ]);

    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->set('isCompose', true)
        ->set('composeServices', ['api'])
        ->set('serviceRedirects.api', 'www')
        ->call('setServiceRedirect', 'api')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    $this->application->refresh();
    $domains = json_decode($this->application->docker_compose_domains, true);
    $apiDomains = explode(',', (string) ($domains['api']['domain'] ?? ''));

    expect($domains['api']['redirect'] ?? null)->toBe('www')
        ->and($apiDomains)->toContain('http://api-docker-compose.127.0.0.1.sslip.io')
        ->and($apiDomains)->toContain('http://www.api-docker-compose.127.0.0.1.sslip.io');
});

it('recovers when serviceRedirects.api is corrupted to a nested array by dotted service names', function () {
    $this->application->update([
        'build_pack' => 'dockercompose',
        'fqdn' => null,
        'docker_compose_raw' => "services:\n  api:\n    image: node:alpine\n  api.test:\n    image: nginx:alpine\n",
        'docker_compose_domains' => json_encode([
            'api' => ['domain' => 'https://api.example.com', 'redirect' => 'both'],
            'api.test' => ['domain' => 'https://api-test.example.com', 'redirect' => 'both'],
        ]),
    ]);

    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->set('isCompose', true)
        ->set('composeServices', ['api', 'api.test'])
        // Simulate broken nested state from wire:model="serviceRedirects.api.test"
        ->set('serviceRedirects', [
            'api' => ['test' => 'www'],
            'api__dot__test' => 'both',
        ])
        ->set('serviceRedirects.api', 'www')
        ->call('setServiceRedirect', 'api')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    $this->application->refresh();
    $domains = json_decode($this->application->docker_compose_domains, true);

    expect($domains['api']['redirect'] ?? null)->toBe('www')
        ->and(explode(',', (string) ($domains['api']['domain'] ?? '')))
        ->toContain('https://www.api.example.com');
});

it('checks dns on suggested www domain rows', function () {
    $settings = InstanceSettings::get();
    $settings->is_dns_validation_enabled = true;
    $settings->save();

    $this->application->update([
        'fqdn' => 'https://coolify-dns-pair-test.invalid',
        'redirect' => 'both',
    ]);

    $component = Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->assertSet('domainRows.1.url', 'https://www.coolify-dns-pair-test.invalid')
        ->assertSet('domainRows.1.is_suggested', true)
        ->call('checkDomainDns', 1);

    expect(in_array($component->get('domainRows.1.dns_status'), ['failed', 'ok', 'skipped'], true))->toBeTrue()
        ->and($component->get('domainRows.1.checked_at'))->not->toBeNull();

    $this->application->refresh();
    $entry = $this->application->domain_dns_statuses['https://www.coolify-dns-pair-test.invalid'] ?? null;
    expect($entry)->toBeArray()
        ->and($entry['status'] ?? null)->toBe($component->get('domainRows.1.dns_status'))
        ->and($entry['checked_at'] ?? null)->not->toBeNull();
});

it('adds a suggested domain to the application', function () {
    $this->application->update([
        'fqdn' => 'https://example.com',
        'redirect' => 'both',
    ]);

    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->call('addSuggestedDomain', 1)
        ->assertDispatched('success');

    $this->application->refresh();
    expect(explode(',', (string) $this->application->fqdn))
        ->toContain('https://example.com')
        ->toContain('https://www.example.com');
});

it('saves after confirming a domain conflict on add', function () {
    Application::factory()->create([
        'uuid' => (string) Str::uuid(),
        'name' => 'Conflicting App',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'fqdn' => 'https://shared.example.com',
        'build_pack' => 'nixpacks',
    ]);

    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->set('newDomain', 'https://shared.example.com')
        ->call('addDomain')
        ->assertSet('showDomainConflictModal', true)
        ->assertSet('pendingAction', 'add')
        ->assertSet('forceSaveDomains', false)
        ->call('confirmDomainUsage')
        ->assertSet('showDomainConflictModal', false)
        ->assertSet('forceSaveDomains', false)
        ->assertSet('pendingAction', null)
        ->assertDispatched('success');

    expect($this->application->fresh()->fqdn)->toBe('https://shared.example.com');
});

it('saves a suggested domain after confirming a domain conflict', function () {
    Application::factory()->create([
        'uuid' => (string) Str::uuid(),
        'name' => 'WWW Conflicting App',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'fqdn' => 'https://www.example.com',
        'build_pack' => 'nixpacks',
    ]);

    $this->application->update([
        'fqdn' => 'https://example.com',
        'redirect' => 'both',
    ]);

    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->call('addSuggestedDomain', 1)
        ->assertSet('showDomainConflictModal', true)
        ->assertSet('pendingAction', 'suggested')
        ->assertSet('forceSaveDomains', false)
        ->call('confirmDomainUsage')
        ->assertSet('showDomainConflictModal', false)
        ->assertSet('pendingAction', null)
        ->assertSet('forceSaveDomains', false)
        ->assertDispatched('success');

    $this->application->refresh();
    expect(explode(',', (string) $this->application->fqdn))
        ->toContain('https://example.com')
        ->toContain('https://www.example.com');
});

it('saves after confirming a domain conflict on edit', function () {
    Application::factory()->create([
        'uuid' => (string) Str::uuid(),
        'name' => 'Edit Conflict App',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'fqdn' => 'https://taken.example.com',
        'build_pack' => 'nixpacks',
    ]);

    $this->application->update(['fqdn' => 'https://original.example.com']);

    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->call('startEdit', 0)
        ->set('editingDomain', 'https://taken.example.com')
        ->call('updateDomain')
        ->assertSet('showDomainConflictModal', true)
        ->assertSet('pendingAction', 'update')
        ->call('confirmDomainUsage')
        ->assertSet('showDomainConflictModal', false)
        ->assertSet('pendingAction', null)
        ->assertDispatched('success');

    expect($this->application->fresh()->fqdn)->toBe('https://taken.example.com');
});

it('clears pending conflict action when the conflict modal is dismissed', function () {
    Application::factory()->create([
        'uuid' => (string) Str::uuid(),
        'name' => 'Dismiss Conflict App',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'fqdn' => 'https://dismiss.example.com',
        'build_pack' => 'nixpacks',
    ]);

    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->set('newDomain', 'https://dismiss.example.com')
        ->call('addDomain')
        ->assertSet('showDomainConflictModal', true)
        ->assertSet('pendingAction', 'add')
        ->set('showDomainConflictModal', false)
        ->assertSet('pendingAction', null)
        ->assertSet('forceSaveDomains', false);

    expect($this->application->fresh()->fqdn)->toBeNull();
});

it('does not show a suggested row when both www variants are configured', function () {
    $this->application->update([
        'fqdn' => 'https://example.com,https://www.example.com',
        'redirect' => 'both',
    ]);

    $component = Livewire::test(Domains::class, ['application' => $this->application->fresh()]);

    expect(collect($component->get('domainRows'))->where('is_suggested', true))->toHaveCount(0);
});

it('groups compose domains by service and shows empty services', function () {
    $this->application->update([
        'build_pack' => 'dockercompose',
        'fqdn' => null,
        'docker_compose_domains' => json_encode([
            'web' => ['domain' => 'https://web.example.com'],
            'api' => ['domain' => null],
        ]),
    ]);

    $component = Livewire::test(Domains::class, ['application' => $this->application->fresh()]);
    // parse() may not yield services in tests; force the compose service list used for grouping.
    $component->set('isCompose', true);
    $component->set('composeServices', ['web', 'api']);
    $component->instance()->domainRows = (function () use ($component) {
        $method = new ReflectionMethod($component->instance(), 'buildDomainRows');

        return $method->invoke($component->instance());
    })();

    $component
        ->assertSee('web')
        ->assertSee('https://web.example.com')
        ->assertSee('api')
        ->assertSee('No domains for this service yet');
});

it('exposes the domains route in the application configuration menu', function () {
    $url = route('project.application.domains', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'application_uuid' => $this->application->uuid,
    ]);

    $this->get($url)
        ->assertSuccessful()
        ->assertSeeLivewire(Domains::class)
        ->assertSee('Domains');
});

it('sets redirect direction per compose service without changing other services', function () {
    $this->application->update([
        'build_pack' => 'dockercompose',
        'fqdn' => null,
        'docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n  api:\n    image: node:alpine\n",
        'docker_compose_domains' => json_encode([
            'web' => ['domain' => 'https://web.example.com', 'redirect' => 'both'],
            'api' => ['domain' => 'https://api.example.com,https://www.api.example.com', 'redirect' => 'both'],
        ]),
    ]);

    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->set('isCompose', true)
        ->set('composeServices', ['web', 'api'])
        ->set('serviceRedirects.web', 'www')
        ->call('setServiceRedirect', 'web')
        ->assertDispatched('success');

    $this->application->refresh();
    $domains = json_decode($this->application->docker_compose_domains, true);

    expect(data_get($domains, 'web.redirect'))->toBe('www')
        ->and(data_get($domains, 'web.domain'))->toContain('https://www.web.example.com')
        ->and(data_get($domains, 'api.redirect'))->toBe('both')
        ->and(data_get($domains, 'api.domain'))->toBe('https://api.example.com,https://www.api.example.com');
});

it('auto-adds missing www pair for a single compose service redirect', function () {
    $this->application->update([
        'build_pack' => 'dockercompose',
        'fqdn' => null,
        'docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n",
        'docker_compose_domains' => json_encode([
            'web' => ['domain' => 'https://web.example.com', 'redirect' => 'both'],
        ]),
    ]);

    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->set('isCompose', true)
        ->set('composeServices', ['web'])
        ->set('serviceRedirects.web', 'www')
        ->call('setServiceRedirect', 'web')
        ->assertDispatched('success');

    $this->application->refresh();
    $domains = json_decode($this->application->docker_compose_domains, true);
    $webDomains = explode(',', (string) data_get($domains, 'web.domain'));

    expect(data_get($domains, 'web.redirect'))->toBe('www')
        ->and($webDomains)->toContain('https://web.example.com')
        ->and($webDomains)->toContain('https://www.web.example.com');
});

it('uses compose service redirect for suggested domain messaging', function () {
    $this->application->update([
        'build_pack' => 'dockercompose',
        'fqdn' => null,
        'docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n",
        'docker_compose_domains' => json_encode([
            'web' => ['domain' => 'https://web.example.com', 'redirect' => 'www'],
        ]),
    ]);

    $component = Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->set('isCompose', true)
        ->set('composeServices', ['web'])
        ->set('serviceRedirects.web', 'www');

    $component->instance()->domainRows = (function () use ($component) {
        $method = new ReflectionMethod($component->instance(), 'buildDomainRows');

        return $method->invoke($component->instance());
    })();

    $suggested = collect($component->get('domainRows'))->firstWhere('is_suggested', true);

    expect($suggested)->not->toBeNull()
        ->and($suggested['suggestion_role'] ?? null)->toBe('canonical')
        ->and($suggested['url'] ?? null)->toBe('https://www.web.example.com');
});
