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
        'fqdn' => 'https://example.com,https://www.example.com,https://another.example.com,https://www.another.example.com',
    ]);

    $html = Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->assertSuccessful()
        ->assertSet('domainRows.0.url', 'https://example.com')
        ->assertSet('domainRows.1.url', 'https://www.example.com')
        ->assertSee('https://example.com')
        ->assertSee('https://www.example.com')
        ->assertSee('https://example.com/favicon.ico', false)
        ->assertSee('class="relative size-4 shrink-0"', false)
        ->assertSee('domain-favicon-fallback', false)
        ->assertSee('class="invisible absolute inset-0 size-4 rounded-sm"', false)
        ->assertSee('$el.previousElementSibling.classList.add(\'hidden\')', false)
        ->assertSee('x-on:error="$el.remove()"', false)
        ->assertSee('class="min-w-0 flex-1 text-[13px]', false)
        ->html();

    expect(substr_count($html, 'this.$wire.updateRedirect('))->toBe(2);
});

it('shows the HTTP redirect control for HTTPS domains and persists changes', function () {
    $this->application->update(['fqdn' => 'https://app.example.com']);

    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->assertSet('isForceHttpsEnabled', true)
        ->assertSee('Redirect HTTP to HTTPS')
        ->assertSee('Keep enabled when Cloudflare uses Full or Full (Strict) SSL.')
        ->set('isForceHttpsEnabled', false)
        ->call('updateForceHttps')
        ->assertHasNoErrors();

    expect($this->application->settings->fresh()->is_force_https_enabled)->toBeFalse();
});

it('hides the HTTP redirect control for HTTP-only domains', function () {
    $this->application->update(['fqdn' => 'http://app.example.com']);

    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->assertDontSee('Redirect HTTP to HTTPS');
});

it('shows one redirect direction control in each compose service header', function () {
    $this->application->update([
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => "services:\n  api:\n    image: nginx:alpine\n",
        'docker_compose_domains' => json_encode([
            'api' => [
                'domain' => 'https://api.example.com,https://www.api.example.com',
                'redirect' => 'www',
            ],
        ]),
    ]);

    $html = Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->assertSuccessful()
        ->assertSee('api')
        ->html();

    expect(substr_count($html, 'this.$wire.updateServiceRedirect('))->toBe(1)
        ->and(substr_count($html, 'this.$wire.updateRedirect('))->toBe(0)
        ->and(substr_count($html, 'domain-direction-service-api'))->toBeGreaterThan(0);
});

it('shows dns entries control next to Add', function () {
    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->assertSuccessful()
        ->assertSee('DNS entries')
        ->assertSee('Manual records');
});

it('shows dns entries when domains are managed through labels', function () {
    $this->application->settings->update([
        'is_container_label_readonly_enabled' => false,
    ]);

    Livewire::test(Domains::class, ['application' => $this->application->fresh(['settings'])])
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

    expect(explode(',', (string) $this->application->fqdn))
        ->toBe(['https://app.example.com', 'https://www.app.example.com']);
});

it('composes the complete port on the server without duplicating an existing www domain', function () {
    $this->application->update(['fqdn' => 'https://www.example.com:3000']);

    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->set('newDomainParts.host', 'example.com')
        ->set('newDomainParts.port', '3000')
        ->call('addDomain')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    expect(explode(',', (string) $this->application->fresh()->fqdn))->toBe([
        'https://www.example.com:3000',
        'https://example.com:3000',
    ]);
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
        ->assertSee('DNS is not pointing to the right IP')
        ->assertSee('Are you sure you want to add it anyway');

    $this->application->refresh();
    expect($this->application->fqdn)->toBeNull();

    $component->call('confirmAddDomainDespiteDns')
        ->assertSet('addDomainDnsFailed', false)
        ->assertDispatched('success')
        ->assertDispatched('close-modal');

    $this->application->refresh();
    expect(explode(',', (string) $this->application->fqdn))->toBe([
        'https://this-domain-should-not-resolve-for-coolify-tests.invalid',
        'https://www.this-domain-should-not-resolve-for-coolify-tests.invalid',
    ]);
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
        ->assertSee('Direction')
        ->assertSee('Search engine indexing')
        ->set('editingDomain', 'https://new.example.com')
        ->call('updateDomain')
        ->assertHasNoErrors()
        ->assertSet('showEditDomainModal', false)
        ->assertDispatched('edit-domain-saved')
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
        ->assertSee('DNS is not pointing to the right IP')
        ->assertSee('Are you sure you want to save it anyway');

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
        ->assertSet('domainRows.1.url', 'https://www.example.com')
        ->assertSet('domainRows.1.checked_at', fn (?string $checkedAt): bool => filled($checkedAt));

    $this->application->refresh();

    expect($this->application->redirect)->toBe('www')
        ->and(explode(',', (string) $this->application->fqdn))
        ->toContain('https://example.com')
        ->toContain('https://www.example.com');
});

it('does not re-add a removed www counterpart on page load when redirect is www', function () {
    $this->application->update([
        'fqdn' => 'https://asd.hu',
        'redirect' => 'www',
    ]);

    // Mount alone must not re-add missing pairs (would undo deletes).
    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->assertSet('domainRows.0.url', 'https://asd.hu');

    expect(explode(',', (string) $this->application->fresh()->fqdn))
        ->toContain('https://asd.hu')
        ->not->toContain('https://www.asd.hu');
});

it('keeps a domain removed when its www counterpart remains and redirect is www', function () {
    $this->application->update([
        'fqdn' => 'https://asd.hu,https://www.asd.hu',
        'redirect' => 'www',
    ]);

    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->call('removeDomain', 0)
        ->assertDispatched('success');

    $this->application->refresh();

    expect(explode(',', (string) $this->application->fqdn))
        ->toContain('https://www.asd.hu')
        ->not->toContain('https://asd.hu');
});

it('auto-adds www pair when adding a domain while redirect is www', function () {
    $settings = InstanceSettings::get();
    $settings->is_dns_validation_enabled = false;
    $settings->save();

    $this->application->update([
        'fqdn' => null,
        'redirect' => 'www',
    ]);

    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->set('newDomain', 'https://app.example.com')
        ->call('addDomain')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    $this->application->refresh();

    expect(explode(',', (string) $this->application->fqdn))
        ->toContain('https://app.example.com')
        ->toContain('https://www.app.example.com');
});

it('auto-adds the suggested www pair when adding a domain with both directions', function () {
    $settings = InstanceSettings::get();
    $settings->is_dns_validation_enabled = false;
    $settings->save();

    $this->application->update([
        'fqdn' => null,
        'redirect' => 'both',
    ]);

    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->set('newDomain', 'https://app.example.com')
        ->call('addDomain')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    expect(explode(',', (string) $this->application->fresh()->fqdn))
        ->toContain('https://app.example.com')
        ->toContain('https://www.app.example.com');
});

it('appends a generated domain without replacing existing domains', function () {
    $this->application->update([
        'fqdn' => 'https://existing.example.com,https://www.existing.example.com',
        'redirect' => 'both',
    ]);

    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->call('generateDomain')
        ->assertDispatched('success')
        ->assertNotDispatched('error');

    $domains = explode(',', (string) $this->application->fresh()->fqdn);

    expect($domains)
        ->toContain('https://existing.example.com')
        ->toContain('https://www.existing.example.com')
        ->toHaveCount(3);
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
        ->assertSee('DNS mismatch')
        ->assertDontSee('DNS does not point to 203.0.113.10.')
        ->call('openDnsRecordsModal')
        ->assertSet('showDnsRecordsModal', true);
});

it('shows dns mismatches before other domain entries', function () {
    $this->application->update([
        'fqdn' => 'https://healthy.example.com,https://broken.example.com',
        'domain_dns_statuses' => [
            'https://healthy.example.com' => ['status' => 'ok', 'message' => 'OK'],
            'https://broken.example.com' => ['status' => 'failed', 'message' => 'Mismatch'],
        ],
    ]);

    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->assertSet('domainRows.0.url', 'https://broken.example.com')
        ->assertSet('domainRows.0.dns_status', 'failed')
        ->assertSet('domainRows.1.url', 'https://healthy.example.com');
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

    // Failed checks show required DNS record guidance; ok checks mention the hostname label.
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
        ->and($component->get('domainRows.0.dns_message'))->toBe('Required DNS record type AAAA pointing to 2001:db8::10');
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

    expect($component->get('domainRows.0.dns_message'))->toBe('Required DNS record type A pointing to 172.16.0.3')
        ->and($component->get('domainRows.0.dns_status'))->toBe('failed');
});

it('normalizes domains before saving', function () {
    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->set('newDomain', 'HTTPS://App.Example.COM/Path')
        ->call('addDomain')
        ->assertHasNoErrors();

    $this->application->refresh();

    expect(explode(',', (string) $this->application->fqdn))->toBe([
        ValidationPatterns::normalizeApplicationDomains('HTTPS://App.Example.COM/Path'),
        'https://www.app.example.com/Path',
    ]);
});

it('does not suggest a missing www counterpart', function () {
    $this->application->update([
        'fqdn' => 'https://example.com',
        'redirect' => 'both',
    ]);

    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->assertSet('domainRows.0.url', 'https://example.com')
        ->assertSet('domainRows.0.is_suggested', false)
        ->assertCount('domainRows', 1)
        ->assertDontSee('Not configured yet.')
        ->assertDontSee('https://www.example.com');
});

it('does not persist redirect until Set Direction saves', function () {
    $this->application->update([
        'fqdn' => 'https://example.com',
        'redirect' => 'both',
    ]);

    $component = Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->set('redirect', 'www');

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

it('preserves per-service redirect when compose domains are updated through the api', function () {
    $this->application->update([
        'build_pack' => 'dockercompose',
        'fqdn' => null,
        'docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n",
        'docker_compose_domains' => json_encode([
            'web' => ['domain' => 'https://old.example.com', 'redirect' => 'both'],
        ]),
    ]);

    $plainTextToken = Str::random(40);
    $token = $this->user->tokens()->create([
        'name' => 'compose-domain-redirect-test',
        'token' => hash('sha256', $plainTextToken),
        'abilities' => ['*'],
        'team_id' => $this->team->id,
    ]);
    auth()->logout();

    $this->withToken($token->getKey().'|'.$plainTextToken)
        ->patchJson("/api/v1/applications/{$this->application->uuid}", [
            'docker_compose_domains' => [[
                'name' => 'web',
                'domain' => 'https://new.example.com',
                'redirect' => 'www',
            ]],
        ])
        ->assertOk();

    $domains = json_decode($this->application->fresh()->docker_compose_domains, true);

    expect($domains['web'])->toBe([
        'domain' => 'https://new.example.com',
        'redirect' => 'www',
    ]);
});

it('preserves omitted compose redirects while allowing explicit null', function () {
    $this->application->update([
        'build_pack' => 'dockercompose',
        'fqdn' => null,
        'docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n",
        'docker_compose_domains' => json_encode([
            'web' => ['domain' => 'https://old.example.com', 'redirect' => 'both'],
        ]),
    ]);

    $plainTextToken = Str::random(40);
    $token = $this->user->tokens()->create([
        'name' => 'legacy-compose-domain-test',
        'token' => hash('sha256', $plainTextToken),
        'abilities' => ['*'],
        'team_id' => $this->team->id,
    ]);
    auth()->logout();

    $this->withToken($token->getKey().'|'.$plainTextToken)
        ->patchJson("/api/v1/applications/{$this->application->uuid}", [
            'docker_compose_domains' => [[
                'name' => 'web',
                'domain' => 'https://new.example.com',
            ]],
        ])
        ->assertOk();

    $domains = json_decode($this->application->fresh()->docker_compose_domains, true);

    expect($domains['web'])->toBe([
        'domain' => 'https://new.example.com',
        'redirect' => 'both',
    ]);

    $this->withToken($token->getKey().'|'.$plainTextToken)
        ->patchJson("/api/v1/applications/{$this->application->uuid}", [
            'docker_compose_domains' => [[
                'name' => 'web',
                'domain' => 'https://new.example.com',
                'redirect' => null,
            ]],
        ])
        ->assertOk();

    $domains = json_decode($this->application->fresh()->docker_compose_domains, true);

    expect($domains['web'])->toBe([
        'domain' => 'https://new.example.com',
    ]);
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

it('groups compose domains by service and hides empty services', function () {
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
        ->assertDontSee('No domains for this service');
});

it('uses the compact service domains layout for compose applications', function () {
    $view = file_get_contents(resource_path('views/livewire/project/application/domains.blade.php'));

    expect($view)
        ->toContain('application-compose-domain-group-{{ $redirectWireKey }}')
        ->toContain('class="application-settings-section-body mt-1 scroll-mt-28')
        ->toContain('bg-neutral-50 px-4 py-3 dark:border-white/10 dark:bg-white/[0.04]')
        ->toContain('class="data-table-header domains-table-grid-service"')
        ->toContain('<span>Direction</span>')
        ->toContain('<span class="whitespace-nowrap">Search engine indexing</span>')
        ->not->toContain('<span>Last checked</span>')
        ->not->toContain('id="edit-domain-direction"')
        ->toContain('id="domain-direction-service-{{ $redirectWireKey }}"')
        ->toContain('onChange="updateServiceRedirect"')
        ->toContain("'showDirectionControl' => false")
        ->not->toContain('title="No domains for this service"');
});

it('keeps search engine indexing table headers on one line', function () {
    $applicationView = file_get_contents(resource_path('views/livewire/project/application/domains.blade.php'));
    $serviceView = file_get_contents(resource_path('views/livewire/project/service/partials/domain-table.blade.php'));

    expect(substr_count($applicationView, '<span class="whitespace-nowrap">Search engine indexing</span>'))
        ->toBe(2)
        ->and($serviceView)
        ->toContain('<span class="whitespace-nowrap">Search engine indexing</span>');
});

it('shows domain guidance in the application domains section', function () {
    $view = file_get_contents(resource_path('views/livewire/project/application/domains.blade.php'));

    expect($view)
        ->toContain('<p class="text-sm text-neutral-500 dark:text-fg-dim">')
        ->toContain('{{ $helperText }}');
});

it('does not render a last checked column in the domains table', function () {
    $view = file_get_contents(resource_path('views/livewire/project/application/domains.blade.php'));
    $row = file_get_contents(resource_path('views/livewire/project/application/partials/domain-row.blade.php'));

    expect($view)->not->toContain('<span>Last checked</span>')
        ->and($row)->not->toContain('$checkedAt');
});

it('uses compact labeled domain cards on mobile', function () {
    $styles = file_get_contents(resource_path('css/app.css'));
    $row = file_get_contents(resource_path('views/livewire/project/application/partials/domain-row.blade.php'));

    expect($styles)
        ->toContain('@media (max-width: 768px)')
        ->toContain('.domains-mobile-label')
        ->toContain('.domains-table-grid .listbox-trigger')
        ->and($row)
        ->toContain('domains-mobile-label')
        ->toContain('Search engine indexing')
        ->toContain('Direction');
});

it('uses segmented fields when adding and editing application domains', function () {
    $view = file_get_contents(resource_path('views/livewire/project/application/domains.blade.php'));
    $component = file_get_contents(resource_path('views/components/forms/domain-input.blade.php'));

    expect($view)
        ->toContain('<x-forms.domain-input id="newDomainParts"')
        ->toContain('<x-forms.domain-input id="editingDomainParts"')
        ->not->toContain('placeholder="https://app.example.com"')
        ->and($component)
        ->toContain('Protocol')
        ->toContain('Domain')
        ->toContain('Port')
        ->toContain('Path')
        ->toContain('wire:model="{{ $id }}.host"')
        ->toContain('<x-forms.listbox id="{{ $id }}.scheme"')
        ->not->toContain('<select id="{{ $id }}-protocol"')
        ->toContain("['value' => 'https', 'label' => 'https']")
        ->toContain("['value' => 'http', 'label' => 'http']")
        ->toContain('class="mb-1.5 flex h-4 w-full items-center gap-1.5"')
        ->not->toContain('class="mb-1.5 block text-sm font-medium"')
        ->toContain('min="1"')
        ->toContain('max="65535"');
});

it('updates a compose service redirect from the domains table', function () {
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
        ->call('updateServiceRedirect', 'web', 'www')
        ->assertDispatched('success');

    $domains = json_decode($this->application->fresh()->docker_compose_domains, true);

    expect(data_get($domains, 'web.redirect'))->toBe('www')
        ->and(data_get($domains, 'web.domain'))->toContain('https://www.web.example.com');
});

it('provides client-side search for compose service domains', function () {
    $view = file_get_contents(resource_path('views/livewire/project/application/domains.blade.php'));

    expect($view)
        ->toContain('x-model="domainSearch"')
        ->toContain('class="ml-auto flex flex-wrap items-center gap-2"')
        ->toContain('<div class="relative shrink-0">')
        ->toContain('placeholder="Search services or domains"')
        ->toContain('x-show="matchesDomainSearch(')
        ->toContain('title="No domains found"')
        ->toContain('hasDomainSearchResults(');
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

it('updates search engine indexing from the domains view', function () {
    $this->application->update(['fqdn' => 'https://app.example.com,https://staging.example.com']);

    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->assertSee('Noindex')
        ->assertSee('Indexable')
        ->assertSee('Search engine indexing')
        ->assertSee('Direction')
        ->assertSee('toggleNoindexDomain', false)
        ->assertSee('updateRedirect', false)
        ->assertSee('wire:ignore', false)
        ->assertDontSee('x-model="localIndexing"', false)
        ->assertDontSee('x-model="localDirection"', false)
        ->assertDontSee('@js(', false)
        ->call('toggleNoindexDomain', 'https://staging.example.com', 'noindex')
        ->assertDispatched('configurationChanged')
        ->assertDispatched('success');

    expect($this->application->refresh()->noindexDomains()->all())
        ->toBe(['https://staging.example.com']);
});
