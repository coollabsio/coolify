<?php

use App\Jobs\CheckDomainDnsJob;
use App\Livewire\Project\Application\Domains;
use App\Livewire\Project\Application\PreviewDomains;
use App\Livewire\Project\Application\Previews;
use App\Models\Application;
use App\Models\ApplicationPreview;
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
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    config(['app.maintenance.driver' => 'file']);

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

it('does not add a single-label hostname as an application domain', function () {
    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->set('newDomainParts.host', 'aaa')
        ->call('addDomain')
        ->assertDispatched('error');

    expect($this->application->fresh()->fqdn)->toBeNull();
});

it('generates a preview domain when the application has no domain', function () {
    $preview = ApplicationPreview::create([
        'application_id' => $this->application->id,
        'pull_request_id' => 41,
        'pull_request_html_url' => 'https://github.com/coollabsio/coolify/pull/41',
    ]);

    Livewire::test(PreviewDomains::class, ['preview' => $preview])
        ->call('generateDomain')
        ->assertDispatched('success');

    expect($preview->fresh()->fqdn)
        ->not->toBeNull()
        ->toContain('41.');
});

it('generates compose preview domains when the application has no domains', function () {
    $this->application->update([
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n",
        'docker_compose_domains' => null,
    ]);

    $preview = ApplicationPreview::create([
        'application_id' => $this->application->id,
        'pull_request_id' => 42,
        'pull_request_html_url' => 'https://github.com/coollabsio/coolify/pull/42',
    ]);

    Livewire::test(PreviewDomains::class, ['preview' => $preview])
        ->call('generateDomain')
        ->assertDispatched('success');

    expect($preview->fresh()->fqdn)
        ->not->toBeNull()
        ->toContain('42.');
});

it('derives preview domain services from compose and defaults the selected service', function () {
    Queue::fake();

    $this->application->update([
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n  worker-pr-49:\n    image: nginx:alpine\n  database:\n    image: postgres:17\n",
        'docker_compose_domains' => null,
    ]);

    $preview = ApplicationPreview::create([
        'application_id' => $this->application->id,
        'pull_request_id' => 49,
        'pull_request_html_url' => 'https://github.com/coollabsio/coolify/pull/49',
        'docker_compose_domains' => json_encode([
            'removed-service' => ['domain' => ''],
        ]),
    ]);

    Livewire::test(PreviewDomains::class, ['preview' => $preview])
        ->assertViewHas('composeServices', ['web', 'worker-pr-49'])
        ->assertSet('newDomainService', 'web')
        ->set('newDomainService', 'worker-pr-49')
        ->set('newDomainParts.host', 'worker-preview.example.com')
        ->call('addDomain')
        ->assertHasNoErrors()
        ->assertSet('newDomainService', 'web');

    $preview->generate_preview_fqdn_compose();

    expect(json_decode($preview->fresh()->docker_compose_domains, true))
        ->toHaveKey('worker-pr-49')
        ->not->toHaveKey('worker');
});

it('handles compose parsing failures without exposing stale preview services', function () {
    $this->application->update([
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => "services: [\n",
        'docker_compose_domains' => null,
    ]);

    $preview = ApplicationPreview::create([
        'application_id' => $this->application->id,
        'pull_request_id' => 52,
        'pull_request_html_url' => 'https://github.com/coollabsio/coolify/pull/52',
        'docker_compose_domains' => json_encode([
            'stale-service' => ['domain' => ''],
        ]),
    ]);

    Livewire::test(PreviewDomains::class, ['preview' => $preview])
        ->assertViewHas('composeServices', [])
        ->assertSet('newDomainService', null);
});

it('does not erase preview domains when compose parsing fails during persistence', function () {
    $this->application->update([
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n  worker:\n    image: nginx:alpine\n",
    ]);

    $storedDomains = [
        'web' => ['domain' => 'https://web-preview.example.com'],
        'worker' => ['domain' => 'https://worker-preview.example.com'],
    ];
    $preview = ApplicationPreview::create([
        'application_id' => $this->application->id,
        'pull_request_id' => 53,
        'pull_request_html_url' => 'https://github.com/coollabsio/coolify/pull/53',
        'docker_compose_domains' => json_encode($storedDomains),
        'fqdn' => 'https://web-preview.example.com,https://worker-preview.example.com',
    ]);

    $component = Livewire::test(PreviewDomains::class, ['preview' => $preview]);

    $application = Mockery::mock($component->instance()->preview->application)->makePartial();
    $application->shouldReceive('parse')->once()->andThrow(new RuntimeException('Temporary parse failure'));
    $component->instance()->preview->setRelation('application', $application);

    $component->instance()->removeDomain(0);

    expect(json_decode($preview->fresh()->docker_compose_domains, true))->toBe($storedDomains)
        ->and($preview->fresh()->fqdn)->toBe('https://web-preview.example.com,https://worker-preview.example.com')
        ->and($component->get('domainRows'))->toHaveCount(2);
});

it('preserves empty compose service slots after removing the last preview domain', function () {
    $this->application->update([
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n  worker:\n    image: nginx:alpine\n",
        'docker_compose_domains' => null,
    ]);

    $preview = ApplicationPreview::create([
        'application_id' => $this->application->id,
        'pull_request_id' => 50,
        'pull_request_html_url' => 'https://github.com/coollabsio/coolify/pull/50',
        'docker_compose_domains' => json_encode([
            'web' => ['domain' => 'https://web-preview.example.com'],
        ]),
        'fqdn' => 'https://web-preview.example.com',
    ]);

    Livewire::test(PreviewDomains::class, ['preview' => $preview])
        ->call('removeDomain', 0)
        ->assertViewHas('composeServices', ['web', 'worker']);

    expect(json_decode($preview->fresh()->docker_compose_domains, true))->toBe([
        'web' => ['domain' => ''],
        'worker' => ['domain' => ''],
    ]);
});

it('rejects missing and unknown services when adding compose preview domains', function (?string $service) {
    Queue::fake();

    $this->application->update([
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n",
        'docker_compose_domains' => null,
    ]);

    $preview = ApplicationPreview::create([
        'application_id' => $this->application->id,
        'pull_request_id' => 51,
        'pull_request_html_url' => 'https://github.com/coollabsio/coolify/pull/51',
    ]);

    Livewire::test(PreviewDomains::class, ['preview' => $preview])
        ->set('newDomainParts.host', 'preview.example.com')
        ->set('newDomainService', $service)
        ->call('addDomain')
        ->assertHasErrors('newDomainService')
        ->assertCount('domainRows', 0);

    expect($preview->fresh()->docker_compose_domains)->toBeNull()
        ->and($preview->fresh()->fqdn)->toBeNull();
    Queue::assertNothingPushed();
})->with([null, 'unknown']);

it('generates a compose preview domain only for the selected service', function () {
    $this->application->update([
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n  api:\n    image: nginx:alpine\n",
        'docker_compose_domains' => json_encode([
            'web' => ['domain' => 'https://web.example.com'],
            'api' => ['domain' => 'https://api.example.com'],
        ]),
    ]);

    $preview = ApplicationPreview::create([
        'application_id' => $this->application->id,
        'pull_request_id' => 48,
        'pull_request_html_url' => 'https://github.com/coollabsio/coolify/pull/48',
        'docker_compose_domains' => json_encode([
            'web' => ['domain' => 'https://custom-web.example.net'],
            'api' => ['domain' => 'https://custom-api.example.net'],
        ]),
        'fqdn' => 'https://custom-web.example.net,https://custom-api.example.net',
    ]);

    Livewire::test(PreviewDomains::class, ['preview' => $preview])
        ->set('newDomainService', 'api')
        ->call('generateDomain')
        ->assertDispatched('success');

    $domains = json_decode($preview->fresh()->docker_compose_domains, true);

    expect($domains['web']['domain'])->toBe('https://custom-web.example.net')
        ->and($domains['api']['domain'])->toStartWith('https://custom-api.example.net,')
        ->and($domains['api']['domain'])->toContain('48.api.example.com');
});

it('keeps compose services without application domains private when configuring a preview', function () {
    $this->application->update([
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n  worker:\n    image: nginx:alpine\n",
        'docker_compose_domains' => json_encode([
            'web' => ['domain' => 'https://web.example.com'],
            'worker' => ['domain' => ''],
        ]),
    ]);

    Livewire::test(Previews::class, ['application' => $this->application->fresh()])
        ->set('parameters', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'application_uuid' => $this->application->uuid,
        ])
        ->call('add', 43, 'https://github.com/coollabsio/coolify/pull/43');

    $preview = ApplicationPreview::query()
        ->where('application_id', $this->application->id)
        ->where('pull_request_id', 43)
        ->firstOrFail();
    $composeDomains = json_decode($preview->docker_compose_domains, true);

    expect($composeDomains['web']['domain'])
        ->toContain('web.example.com')
        ->and($composeDomains['worker']['domain'])->toBe('')
        ->and($preview->fqdn)->toContain('web.example.com')
        ->not->toContain('worker');
});

it('generates a domain when configuring a preview', function () {
    Livewire::test(Previews::class, ['application' => $this->application->fresh()])
        ->set('parameters', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'application_uuid' => $this->application->uuid,
        ])
        ->call('add', 43, 'https://github.com/coollabsio/coolify/pull/43')
        ->assertDispatched('success');

    $preview = ApplicationPreview::query()
        ->where('application_id', $this->application->id)
        ->where('pull_request_id', 43)
        ->firstOrFail();

    expect($preview->fqdn)
        ->not->toBeNull()
        ->toContain('43.');
});

it('manages preview domains and their dns status', function () {
    $preview = ApplicationPreview::create([
        'application_id' => $this->application->id,
        'pull_request_id' => 44,
        'pull_request_html_url' => 'https://github.com/coollabsio/coolify/pull/44',
        'fqdn' => 'https://44.example.com',
    ]);

    Livewire::test(PreviewDomains::class, ['preview' => $preview])
        ->assertSet('domainRows.0.url', 'https://44.example.com')
        ->call('checkDomainDns', 0)
        ->assertSet('domainRows.0.dns_status', 'skipped')
        ->set('newDomainParts.host', 'second.example.com')
        ->call('addDomain')
        ->assertHasNoErrors()
        ->assertDispatched('success')
        ->assertCount('domainRows', 2)
        ->call('removeDomain', 0)
        ->assertCount('domainRows', 1);

    expect($preview->fresh()->fqdn)->toBe('https://second.example.com')
        ->and($preview->fresh()->domain_dns_statuses)->not->toBeNull();
});

it('removes the intended preview domains by stable identities after reindexing', function () {
    $domains = [
        'https://first.example.com',
        'https://second.example.com',
        'https://third.example.com',
    ];
    $domainKeys = array_map(fn (string $domain): string => hash('sha256', $domain.'|'), $domains);
    $preview = ApplicationPreview::create([
        'application_id' => $this->application->id,
        'pull_request_id' => 47,
        'pull_request_html_url' => 'https://github.com/coollabsio/coolify/pull/47',
        'fqdn' => implode(',', $domains),
    ]);

    Livewire::test(PreviewDomains::class, ['preview' => $preview])
        ->call('removeDomainByKey', $domainKeys[0])
        ->assertSet('domainRows.0.url', $domains[1])
        ->call('removeDomainByKey', $domainKeys[1])
        ->assertCount('domainRows', 1)
        ->assertSet('domainRows.0.url', $domains[2]);

    expect($preview->fresh()->fqdn)->toBe($domains[2]);
});

it('renders preview domain delete confirmations with stable keys', function () {
    $domains = [
        'https://first.example.com',
        'https://second.example.com',
    ];
    $preview = ApplicationPreview::create([
        'application_id' => $this->application->id,
        'pull_request_id' => 48,
        'pull_request_html_url' => 'https://github.com/coollabsio/coolify/pull/48',
        'fqdn' => implode(',', $domains),
    ]);

    $renderedHtml = html_entity_decode(
        Livewire::test(PreviewDomains::class, ['preview' => $preview])->html(),
        ENT_QUOTES,
    );

    foreach ($domains as $domain) {
        $domainKey = hash('sha256', $domain.'|');

        expect($renderedHtml)->toMatch("/submitAction:\\s*[\"']removeDomainByKey\\({$domainKey}\\)[\"']/");
    }

    expect($renderedHtml)->not->toMatch('/submitAction:\\s*[\"\']removeDomain\\(\\d+\\)[\"\']/');
});

it('removes only the matching compose preview domain when services share a URL', function () {
    $url = 'https://shared.example.com';
    $this->application->update([
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n  worker:\n    image: nginx:alpine\n",
    ]);
    $preview = ApplicationPreview::create([
        'application_id' => $this->application->id,
        'pull_request_id' => 49,
        'pull_request_html_url' => 'https://github.com/coollabsio/coolify/pull/49',
        'docker_compose_domains' => json_encode([
            'web' => ['domain' => $url],
            'worker' => ['domain' => $url],
        ]),
    ]);

    Livewire::test(PreviewDomains::class, ['preview' => $preview])
        ->call('removeDomainByKey', hash('sha256', $url.'|worker'))
        ->assertCount('domainRows', 1)
        ->assertSet('domainRows.0.url', $url)
        ->assertSet('domainRows.0.service', 'web');

    expect(json_decode($preview->fresh()->docker_compose_domains, true))->toBe([
        'web' => ['domain' => $url],
        'worker' => ['domain' => ''],
    ]);
});

it('adds a preview domain and starts its dns check asynchronously', function () {
    Queue::fake();

    $preview = ApplicationPreview::create([
        'application_id' => $this->application->id,
        'pull_request_id' => 45,
        'pull_request_html_url' => 'https://github.com/coollabsio/coolify/pull/45',
    ]);

    Livewire::test(PreviewDomains::class, ['preview' => $preview])
        ->set('newDomainParts.host', 'preview.example.com')
        ->call('addDomain')
        ->assertCount('domainRows', 1)
        ->assertSet('domainRows.0.dns_status', 'checking')
        ->assertDispatched('success', 'Domain added. DNS check started.');

    Queue::assertPushed(CheckDomainDnsJob::class, fn (CheckDomainDnsJob $job): bool => $job->resource->is($preview)
        && $job->url === 'https://preview.example.com');

    expect($preview->fresh()->fqdn)->toBe('https://preview.example.com')
        ->and(collect($preview->fresh()->domain_dns_statuses)->first()['status'])->toBe('checking');
});

it('notifies when an asynchronous preview dns check finds a mismatch', function () {
    $url = 'https://preview.example.com';
    $statusKey = hash('sha256', $url.'|');
    $preview = ApplicationPreview::create([
        'application_id' => $this->application->id,
        'pull_request_id' => 46,
        'pull_request_html_url' => 'https://github.com/coollabsio/coolify/pull/46',
        'fqdn' => $url,
        'domain_dns_statuses' => [
            $statusKey => [
                'status' => 'checking',
                'message' => 'Checking DNS...',
                'check_id' => 'preview-check',
            ],
        ],
    ]);

    $component = Livewire::test(PreviewDomains::class, ['preview' => $preview])
        ->assertSet('domainRows.0.dns_status', 'checking');

    $preview->update([
        'domain_dns_statuses' => [
            $statusKey => [
                'status' => 'failed',
                'message' => 'Required DNS record type A pointing to 203.0.113.10',
                'expected_ip' => '203.0.113.10',
                'checked_at' => now()->toIso8601String(),
            ],
        ],
    ]);

    $component->call('pollDnsChecks')
        ->assertSet('domainRows.0.dns_status', 'failed')
        ->assertDispatched('error', 'DNS is not configured for preview.example.com. Review the required DNS record.');
});

it('does not overwrite a completed preview dns result with stale checking state', function () {
    $url = 'https://preview.example.com';
    $statusKey = hash('sha256', $url.'|');
    $preview = ApplicationPreview::create([
        'application_id' => $this->application->id,
        'pull_request_id' => 48,
        'pull_request_html_url' => 'https://github.com/coollabsio/coolify/pull/48',
        'fqdn' => $url,
        'domain_dns_statuses' => [
            $statusKey => [
                'status' => 'checking',
                'message' => 'Checking DNS...',
                'check_id' => 'stale-check',
            ],
        ],
    ]);

    $component = Livewire::test(PreviewDomains::class, ['preview' => $preview]);

    $preview->update([
        'domain_dns_statuses' => [
            $statusKey => [
                'status' => 'ok',
                'message' => 'DNS looks correct.',
                'check_id' => 'completed-check',
            ],
        ],
    ]);

    $method = new ReflectionMethod($component->instance(), 'persistDnsStatuses');
    $method->invoke($component->instance());

    expect($preview->fresh()->domain_dns_statuses[$statusKey])
        ->toMatchArray([
            'status' => 'ok',
            'message' => 'DNS looks correct.',
            'check_id' => 'completed-check',
        ]);
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
        ->assertDispatched('success', 'Domain added. DNS check started.')
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

    $application = $this->application->fresh();

    expect(explode(',', (string) $application->fqdn))->toBe([
        'https://www.example.com',
        'https://example.com',
    ])->and($application->domain_port_overrides)->toBe([
        'https://www.example.com' => 3000,
        'https://example.com' => 3000,
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

it('saves a domain before checking dns in a separate request', function () {
    Queue::fake();

    $settings = InstanceSettings::get();
    $settings->is_dns_validation_enabled = true;
    $settings->save();

    $component = Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->set('newDomain', 'https://this-domain-should-not-resolve-for-coolify-tests.invalid')
        ->call('addDomain')
        ->assertSet('addDomainDnsFailed', false)
        ->assertSet('domainRows.0.dns_status', 'checking')
        ->assertDispatched('success')
        ->assertDispatched('close-modal');

    $this->application->refresh();
    expect(explode(',', (string) $this->application->fqdn))->toBe([
        'https://this-domain-should-not-resolve-for-coolify-tests.invalid',
        'https://www.this-domain-should-not-resolve-for-coolify-tests.invalid',
    ]);

    expect($this->application->domain_dns_statuses['https://this-domain-should-not-resolve-for-coolify-tests.invalid']['status'] ?? null)
        ->toBe('checking');

    Queue::assertPushed(CheckDomainDnsJob::class, 2);

    $jobs = Queue::pushed(CheckDomainDnsJob::class);

    expect($jobs->pluck('statusKey')->all())->toEqualCanonicalizing([
        'https://this-domain-should-not-resolve-for-coolify-tests.invalid',
        'https://www.this-domain-should-not-resolve-for-coolify-tests.invalid',
    ])->and($jobs->pluck('checkId')->unique())->toHaveCount(2);
});

it('resets the dns gate when the domain input changes', function () {
    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->set('addDomainDnsFailed', true)
        ->set('forceSaveDns', true)
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
        ->set('editingDomainParts.scheme', 'https')
        ->set('editingDomainParts.host', 'new.example.com')
        ->call('updateDomain')
        ->assertHasNoErrors()
        ->assertSet('showEditDomainModal', false)
        ->assertDispatched('edit-domain-saved')
        ->assertDispatched('success')
        ->assertNotDispatched('error');

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
        ->set('editingDomainParts.scheme', 'https')
        ->set('editingDomainParts.host', 'this-domain-should-not-resolve-for-coolify-tests.invalid')
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

it('removes consecutive domains by stable row identity after indexes change', function () {
    $this->application->update([
        'fqdn' => 'https://first.example.com,https://second.example.com,https://third.example.com',
    ]);

    $component = Livewire::test(Domains::class, ['application' => $this->application->fresh()]);

    $component
        ->call('removeDomainByKey', hash('sha256', 'https://first.example.com|'))
        ->call('removeDomainByKey', hash('sha256', 'https://second.example.com|'))
        ->assertDispatched('success');

    expect($this->application->fresh()->fqdn)->toBe('https://third.example.com');
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

it('polls a queued dns check and notifies about a mismatch', function () {
    $domain = 'https://app.example.com';
    $this->application->update([
        'fqdn' => $domain,
        'domain_dns_statuses' => [
            $domain => [
                'status' => 'checking',
                'message' => 'Checking DNS...',
                'expected_ip' => '203.0.113.10',
                'checked_at' => null,
            ],
        ],
    ]);

    $component = Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->assertSee('Checking DNS...')
        ->assertSee('wire:poll.2000ms="pollDnsChecks"', false);

    $this->application->update([
        'domain_dns_statuses' => [
            $domain => [
                'status' => 'failed',
                'message' => 'Required DNS record type A pointing to 203.0.113.10',
                'expected_ip' => '203.0.113.10',
                'checked_at' => now()->toIso8601String(),
            ],
        ],
    ]);

    $component->call('pollDnsChecks')
        ->assertSet('domainRows.0.dns_status', 'failed')
        ->assertDispatched('error', 'DNS is not configured for app.example.com. Review the required DNS record.');
});

it('does not overwrite a completed queued dns result with stale checking state', function () {
    $domain = 'https://app.example.com';
    $this->application->update([
        'fqdn' => $domain,
        'domain_dns_statuses' => [
            $domain => [
                'status' => 'checking',
                'message' => 'Checking DNS...',
                'expected_ip' => '203.0.113.10',
                'checked_at' => null,
            ],
        ],
    ]);

    $component = Livewire::test(Domains::class, ['application' => $this->application->fresh()]);

    $this->application->update([
        'domain_dns_statuses' => [
            $domain => [
                'status' => 'ok',
                'message' => 'DNS looks correct.',
                'expected_ip' => '203.0.113.10',
                'checked_at' => now()->toIso8601String(),
            ],
        ],
    ]);

    $method = new ReflectionMethod($component->instance(), 'persistDomainDnsStatuses');
    $method->invoke($component->instance());

    expect($this->application->fresh()->domain_dns_statuses[$domain]['status'])->toBe('ok');
});

it('does not overwrite a newer queued dns check with stale completed component state', function () {
    $domain = 'https://app.example.com';
    $status = [
        'status' => 'ok',
        'message' => 'DNS looks correct.',
        'expected_ip' => '203.0.113.10',
        'checked_at' => null,
        'check_id' => null,
    ];
    $this->application->update([
        'fqdn' => $domain,
        'domain_dns_statuses' => [$domain => $status],
    ]);

    $component = Livewire::test(Domains::class, ['application' => $this->application->fresh()]);

    $status['check_id'] = 'newer-check';
    $this->application->update(['domain_dns_statuses' => [$domain => $status]]);

    $method = new ReflectionMethod($component->instance(), 'persistDomainDnsStatuses');
    $method->invoke($component->instance());

    expect($this->application->fresh()->domain_dns_statuses[$domain]['check_id'])->toBe('newer-check');
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

    expect(explode(',', (string) $this->application->fresh()->fqdn))->toBe([
        'https://shared.example.com',
        'https://www.shared.example.com',
    ]);
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
        ->set('editingDomainParts.scheme', 'https')
        ->set('editingDomainParts.host', 'taken.example.com')
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

it('saves domain port overrides separately from the public FQDN', function () {
    $this->application->update([
        'fqdn' => 'https://one.example.com:3000,https://two.example.com:8080',
    ]);

    $this->application->refresh();

    expect($this->application->fqdn)
        ->toBe('https://one.example.com,https://two.example.com')
        ->and($this->application->domain_port_overrides)
        ->toBe([
            'https://one.example.com' => 3000,
            'https://two.example.com' => 8080,
        ]);
});

it('retains an existing domain port override when saving a portless domain', function () {
    $this->application->update([
        'fqdn' => 'https://one.example.com:3000',
    ]);

    $this->application->update([
        'fqdn' => 'https://one.example.com',
    ]);

    expect($this->application->fresh()->fqdn)
        ->toBe('https://one.example.com')
        ->and($this->application->fresh()->domain_port_overrides)
        ->toBe(['https://one.example.com' => 3000]);
});

it('prunes a domain port override when that domain is removed', function () {
    $this->application->update([
        'fqdn' => 'https://one.example.com:3000,https://two.example.com:8080',
    ]);

    $this->application->update([
        'fqdn' => 'https://two.example.com',
    ]);

    expect($this->application->fresh()->fqdn)
        ->toBe('https://two.example.com')
        ->and($this->application->fresh()->domain_port_overrides)
        ->toBe(['https://two.example.com' => 8080]);
});

it('keeps a legacy port-bearing application domain after refresh and reparse', function () {
    $this->application->update([
        'fqdn' => 'https://legacy.example.com',
    ]);

    DB::table('applications')->where('id', $this->application->id)->update([
        'fqdn' => 'https://legacy.example.com:9090',
    ]);

    $application = Application::find($this->application->id);
    $application->refresh();

    expect($application->fqdn)->toBe('https://legacy.example.com:9090');

    applicationParser($application);
    $application->update(['description' => 'unrelated reparse']);

    expect($application->fresh()->fqdn)->toBe('https://legacy.example.com:9090');
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

it('keeps noindex domains when normalizing a custom domain port', function () {
    $this->application->update([
        'fqdn' => 'https://staging.example.com:8080',
        'noindex_domains' => ['https://staging.example.com:8080'],
    ]);

    expect($this->application->refresh())
        ->fqdn->toBe('https://staging.example.com')
        ->noindex_domains->toBe(['https://staging.example.com'])
        ->and($this->application->domain_port_overrides)
        ->toBe(['https://staging.example.com' => 8080]);
});

it('saves a port override from the segmented add-domain form', function () {
    $this->application->update(['ports_exposes' => '3000,8080']);

    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->set('newDomainParts.host', 'example.com')
        ->set('newDomainParts.port', '8080')
        ->call('addDomain')
        ->assertHasNoErrors()
        ->assertDispatched('success')
        ->assertSee('Internal port 8080')
        ->assertDontSee('https://example.com:8080');

    $this->application->refresh();

    expect(explode(',', (string) $this->application->fqdn))
        ->toContain('https://example.com')
        ->not->toContain('https://example.com:8080')
        ->and($this->application->domain_port_overrides['https://example.com'] ?? null)
        ->toBe(8080);
});

it('rejects adding a domain whose portless URL is already configured', function () {
    $this->application->update([
        'fqdn' => 'https://example.com:3000',
    ]);

    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->set('newDomainParts.host', 'example.com')
        ->set('newDomainParts.port', '8080')
        ->call('addDomain')
        ->assertHasErrors('newDomain');

    expect($this->application->fresh()->fqdn)->toBe('https://example.com')
        ->and($this->application->fresh()->domain_port_overrides)
        ->toBe(['https://example.com' => 3000]);
});

it('rejects renaming a domain to a port variant of another configured domain', function () {
    $this->application->update([
        'fqdn' => 'https://first.example.com:3000,https://second.example.com:4000',
    ]);

    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->call('startEdit', 1)
        ->set('editingDomainParts.host', 'first.example.com')
        ->set('editingDomainParts.port', '8080')
        ->call('updateDomain')
        ->assertHasErrors('editingDomain');

    expect($this->application->fresh()->fqdn)
        ->toBe('https://first.example.com,https://second.example.com')
        ->and($this->application->fresh()->domain_port_overrides)
        ->toBe([
            'https://first.example.com' => 3000,
            'https://second.example.com' => 4000,
        ]);
});

it('composes segmented add-domain fields into a port override when the changed flag is false', function () {
    $this->application->update(['ports_exposes' => '3000,8080']);

    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->set('newDomainParts.host', 'example.com')
        ->set('newDomainParts.port', '8080')
        ->set('newDomainPartsChanged', false)
        ->call('addDomain')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    expect($this->application->fresh()->fqdn)
        ->toContain('https://example.com')
        ->not->toContain(':8080')
        ->and($this->application->fresh()->domain_port_overrides)
        ->toHaveKey('https://example.com', 8080);
});

it('retains different port overrides for two application domains', function () {
    $this->application->update(['ports_exposes' => '3000,8080']);

    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->set('newDomainParts.host', 'one.example.com')
        ->set('newDomainParts.port', '3000')
        ->call('addDomain')
        ->assertHasNoErrors()
        ->set('newDomainParts.host', 'two.example.com')
        ->set('newDomainParts.port', '8080')
        ->call('addDomain')
        ->assertHasNoErrors();

    $this->application->refresh();

    expect($this->application->domain_port_overrides['https://one.example.com'] ?? null)->toBe(3000)
        ->and($this->application->domain_port_overrides['https://two.example.com'] ?? null)->toBe(8080)
        ->and(explode(',', (string) $this->application->fqdn))
        ->toContain('https://one.example.com')
        ->toContain('https://two.example.com')
        ->not->toContain('https://one.example.com:3000')
        ->not->toContain('https://two.example.com:8080');
});

it('reopens edit with the saved domain port override', function () {
    $this->application->update([
        'ports_exposes' => '3000,8080',
        'fqdn' => 'https://example.com:8080',
    ]);

    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->assertSet('domainRows.0.url', 'https://example.com')
        ->assertSet('domainRows.0.internal_port', 8080)
        ->assertSet('domainRows.0.has_port_override', true)
        ->call('startEdit', 0)
        ->assertSet('editingDomainParts.port', '8080')
        ->assertSet('editingDomainParts.host', 'example.com');
});

it('does not prefill the default internal port when editing a domain without a port override', function () {
    $this->application->update([
        'ports_exposes' => '3000,8080',
        'fqdn' => 'https://example.com',
        'domain_port_overrides' => null,
    ]);

    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->assertSet('domainRows.0.internal_port', 3000)
        ->assertSet('domainRows.0.has_port_override', false)
        ->call('startEdit', 0)
        ->assertSet('editingDomainParts.port', '');
});

it('clears a domain port override and shows the default internal port', function () {
    $this->application->update([
        'ports_exposes' => '3000,8080',
        'fqdn' => 'https://one.example.com:8080,https://two.example.com:9090',
    ]);

    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->assertSee('Internal port 8080')
        ->call('startEdit', 0)
        ->assertSet('editingDomainParts.port', '8080')
        ->set('editingDomainParts.port', '')
        ->call('updateDomain')
        ->assertHasNoErrors()
        ->assertSee('Internal port 3000')
        ->assertSee('Internal port 9090')
        ->assertDontSee('Internal port 8080');

    $this->application->refresh();

    expect($this->application->fqdn)
        ->toContain('https://one.example.com')
        ->and($this->application->domain_port_overrides)
        ->not->toHaveKey('https://one.example.com')
        ->toHaveKey('https://two.example.com', 9090);
});

it('prunes a domain port override when removing the domain from the ui', function () {
    $this->application->update([
        'ports_exposes' => '3000,8080',
        'fqdn' => 'https://one.example.com:8080,https://two.example.com:3000',
    ]);

    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->call('removeDomain', 0)
        ->assertDispatched('success');

    $this->application->refresh();

    expect($this->application->fqdn)->toBe('https://two.example.com')
        ->and($this->application->domain_port_overrides)
        ->toBe(['https://two.example.com' => 3000]);
});

it('renders a portless domain link with an internal port badge for overrides', function () {
    $this->application->update([
        'ports_exposes' => '3000,8080',
        'fqdn' => 'https://example.com:8080',
    ]);

    $html = Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->assertSee('Internal port 8080')
        ->assertSee('https://example.com')
        ->assertDontSee('https://example.com:8080')
        ->html();

    expect($html)
        ->toContain('href="'.getFqdnWithoutPort('https://example.com').'"')
        ->not->toContain('href="https://example.com:8080"')
        ->toContain('Custom internal port for this domain')
        ->not->toContain('Inherited from Ports Exposes');
});

it('shows an error badge when a domain has no internal port and ports exposes is empty', function () {
    $this->application->update([
        'ports_exposes' => null,
        'fqdn' => 'https://example.com',
        'domain_port_overrides' => null,
    ]);

    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->assertSet('domainRows.0.internal_port', null)
        ->assertSee('No internal port')
        ->assertDontSee('Internal port ')
        ->assertSee('table-badge-danger', false);
});

it('keeps the internal port badge when a domain override exists without ports exposes', function () {
    $this->application->update([
        'ports_exposes' => null,
        'fqdn' => 'https://example.com',
        'domain_port_overrides' => [
            'https://example.com' => 8080,
        ],
    ]);

    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->assertSee('Internal port 8080')
        ->assertDontSee('No internal port');
});

it('distinguishes an inherited internal port from a domain port override', function () {
    $this->application->update([
        'ports_exposes' => '3000,8080',
        'fqdn' => 'https://example.com',
        'domain_port_overrides' => null,
    ]);

    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->assertSee('Internal port 3000')
        ->assertSee('Inherited from Ports Exposes', false)
        ->assertDontSee('Custom internal port for this domain', false);
});

it('shows the detected compose service port as the inherited internal port', function () {
    $this->application->update([
        'build_pack' => 'dockercompose',
        'ports_exposes' => '3000',
        'docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n    expose:\n      - '8069'\n",
        'docker_compose_domains' => json_encode([
            'web' => ['domain' => 'https://example.com'],
        ]),
        'fqdn' => null,
        'domain_port_overrides' => null,
    ]);

    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->assertSet('domainRows.0.internal_port', 8069)
        ->assertSet('domainRows.0.has_port_override', false)
        ->assertSee('Internal port 8069')
        ->assertDontSee('Internal port 3000');
});

it('shows the detected compose service port for preview domains', function () {
    $this->application->update([
        'build_pack' => 'dockercompose',
        'ports_exposes' => '3000',
        'docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n    ports:\n      - '18069:8069'\n",
    ]);

    $preview = ApplicationPreview::create([
        'application_id' => $this->application->id,
        'pull_request_id' => 8069,
        'pull_request_html_url' => 'https://github.com/coollabsio/coolify/pull/8069',
        'docker_compose_domains' => json_encode([
            'web' => ['domain' => 'https://preview.example.com'],
        ]),
    ]);

    Livewire::test(PreviewDomains::class, ['preview' => $preview])
        ->assertSet('domainRows.0.internal_port', 8069)
        ->assertSet('domainRows.0.has_port_override', false)
        ->assertSee('Internal port 8069')
        ->assertDontSee('Internal port 3000');
});

it('keeps a legacy port-bearing url port in the edit field as an internal port override', function () {
    $this->application->update([
        'ports_exposes' => '3000,8080',
        'fqdn' => 'https://legacy.example.com',
    ]);

    DB::table('applications')->where('id', $this->application->id)->update([
        'fqdn' => 'https://legacy.example.com:9090',
        'domain_port_overrides' => null,
    ]);

    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->assertSet('domainRows.0.url', 'https://legacy.example.com:9090')
        ->assertSet('domainRows.0.internal_port', 9090)
        ->assertSet('domainRows.0.has_port_override', true)
        ->assertSee('Internal port 9090')
        ->call('startEdit', 0)
        ->assertSet('editingDomainParts.port', '9090');
});

it('stores compose domain port overrides without wiping other services', function () {
    $this->application->update([
        'build_pack' => 'dockercompose',
        'fqdn' => null,
        'ports_exposes' => '3000,8080',
        'docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n  api:\n    image: node:alpine\n",
        'docker_compose_domains' => json_encode([
            'api' => ['domain' => 'https://api.example.com', 'redirect' => 'both'],
        ]),
        'domain_port_overrides' => [
            'https://api.example.com' => 4000,
        ],
    ]);

    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->set('newDomainService', 'web')
        ->set('newDomainParts.host', 'web.example.com')
        ->set('newDomainParts.port', '8080')
        ->set('newDomainPartsChanged', false)
        ->call('addDomain')
        ->assertHasNoErrors()
        ->assertSee('Internal port 8080');

    $this->application->refresh();
    $domains = json_decode($this->application->docker_compose_domains, true);

    expect(data_get($domains, 'web.domain'))
        ->toContain('https://web.example.com')
        ->not->toContain(':8080')
        ->and($this->application->fqdn)->toBeNull()
        ->and($this->application->domain_port_overrides)
        ->toHaveKey('https://web.example.com', 8080)
        ->toHaveKey('https://api.example.com', 4000);
});

it('prunes a compose domain port override when that domain is removed', function () {
    $this->application->update([
        'build_pack' => 'dockercompose',
        'fqdn' => null,
        'ports_exposes' => '3000,8080',
        'docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n  api:\n    image: node:alpine\n",
        'docker_compose_domains' => json_encode([
            'web' => ['domain' => 'https://web.example.com', 'redirect' => 'both'],
            'api' => ['domain' => 'https://api.example.com', 'redirect' => 'both'],
        ]),
        'domain_port_overrides' => [
            'https://web.example.com' => 8080,
            'https://api.example.com' => 4000,
        ],
    ]);

    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->call('removeDomain', 0)
        ->assertDispatched('success');

    $this->application->refresh();

    expect($this->application->domain_port_overrides)
        ->not->toHaveKey('https://web.example.com')
        ->toHaveKey('https://api.example.com', 4000)
        ->and($this->application->fqdn)->toBeNull();
});

function applicationDomainPortOverrideApiToken(User $user, Team $team): string
{
    $plainTextToken = Str::random(40);
    $token = $user->tokens()->create([
        'name' => 'application-domain-port-override-api',
        'token' => hash('sha256', $plainTextToken),
        'abilities' => ['*'],
        'team_id' => $team->id,
    ]);
    auth()->logout();

    return $token->getKey().'|'.$plainTextToken;
}

it('application domain port override API update containing a port persists a portless FQDN and override', function () {
    $bearer = applicationDomainPortOverrideApiToken($this->user, $this->team);

    $this->withToken($bearer)
        ->patchJson("/api/v1/applications/{$this->application->uuid}", [
            'domains' => 'https://example.com:8080',
        ])
        ->assertOk();

    $application = $this->application->fresh();

    expect($application->fqdn)->toBe('https://example.com')
        ->and($application->domain_port_overrides)
        ->toBe(['https://example.com' => 8080]);
});

it('application domain port override API update omitting ports preserves overrides for unchanged domains', function () {
    $this->application->update([
        'fqdn' => 'https://one.example.com:3000,https://two.example.com:8080',
    ]);

    $bearer = applicationDomainPortOverrideApiToken($this->user, $this->team);

    $this->withToken($bearer)
        ->patchJson("/api/v1/applications/{$this->application->uuid}", [
            'domains' => 'https://one.example.com,https://two.example.com',
        ])
        ->assertOk();

    $application = $this->application->fresh();

    expect($application->fqdn)->toBe('https://one.example.com,https://two.example.com')
        ->and($application->domain_port_overrides)
        ->toBe([
            'https://one.example.com' => 3000,
            'https://two.example.com' => 8080,
        ]);
});

it('application domain port override API domain removal prunes the override', function () {
    $this->application->update([
        'fqdn' => 'https://one.example.com:3000,https://two.example.com:8080',
    ]);

    $bearer = applicationDomainPortOverrideApiToken($this->user, $this->team);

    $this->withToken($bearer)
        ->patchJson("/api/v1/applications/{$this->application->uuid}", [
            'domains' => 'https://two.example.com',
        ])
        ->assertOk();

    $application = $this->application->fresh();

    expect($application->fqdn)->toBe('https://two.example.com')
        ->and($application->domain_port_overrides)
        ->toBe(['https://two.example.com' => 8080]);
});

it('application domain port override API update of an unrelated field does not rewrite a legacy FQDN', function () {
    $this->application->update([
        'fqdn' => 'https://legacy.example.com',
        'description' => 'before',
    ]);

    DB::table('applications')->where('id', $this->application->id)->update([
        'fqdn' => 'https://legacy.example.com:9090',
    ]);

    $bearer = applicationDomainPortOverrideApiToken($this->user, $this->team);

    $this->withToken($bearer)
        ->getJson("/api/v1/applications/{$this->application->uuid}")
        ->assertOk();

    expect($this->application->fresh()->fqdn)->toBe('https://legacy.example.com:9090');

    $this->withToken($bearer)
        ->patchJson("/api/v1/applications/{$this->application->uuid}", [
            'description' => 'unrelated',
        ])
        ->assertOk();

    $application = $this->application->fresh();

    expect($application->fqdn)->toBe('https://legacy.example.com:9090')
        ->and($application->description)->toBe('unrelated')
        ->and($application->domain_port_overrides)->toBeNull();
});

it('treats ports exposes and existing domain ports as available internal ports', function () {
    $this->application->update([
        'ports_exposes' => '3000,8080',
        'fqdn' => 'https://one.example.com:9090',
    ]);

    $application = $this->application->fresh();

    expect($application->availableInternalPorts())->toBe([3000, 8080, 9090])
        ->and($application->portRequiresConfirmation(3000))->toBeFalse()
        ->and($application->portRequiresConfirmation(8080))->toBeFalse()
        ->and($application->portRequiresConfirmation(9090))->toBeFalse()
        ->and($application->portRequiresConfirmation(5555))->toBeTrue()
        ->and($application->portRequiresConfirmation(null))->toBeFalse();
});

it('shows a port warning when an application domain uses a port that is not exposed or already used', function () {
    $this->application->update(['ports_exposes' => '3000,8080']);

    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->set('newDomainParts.host', 'example.com')
        ->set('newDomainParts.port', '5555')
        ->call('addDomain')
        ->assertSet('showPortWarningModal', true)
        ->assertSet('unrecognizedPort', 5555)
        ->assertSee('Use a different port?');

    expect($this->application->fresh()->fqdn)->toBeNull();
});

it('saves an unrecognized application domain port after confirming the warning', function () {
    $this->application->update(['ports_exposes' => '3000,8080']);

    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->set('newDomainParts.host', 'example.com')
        ->set('newDomainParts.port', '5555')
        ->call('addDomain')
        ->assertSet('showPortWarningModal', true)
        ->call('confirmUseUnknownPort')
        ->assertSet('showPortWarningModal', false)
        ->assertDispatched('success');

    $application = $this->application->fresh();

    expect(explode(',', (string) $application->fqdn))
        ->toContain('https://example.com')
        ->and($application->domain_port_overrides['https://example.com'] ?? null)->toBe(5555);
});

it('does not warn when editing an application domain to a port already used by another domain', function () {
    $this->application->update([
        'ports_exposes' => '3000',
        'fqdn' => 'https://one.example.com:9090,https://two.example.com',
    ]);

    Livewire::test(Domains::class, ['application' => $this->application->fresh()])
        ->call('startEdit', 1)
        ->set('editingDomainParts.port', '9090')
        ->call('updateDomain')
        ->assertSet('showPortWarningModal', false)
        ->assertDispatched('success');
});
