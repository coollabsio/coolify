<?php

use App\Livewire\Project\Application\PreviewDomains;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        'generate_exact_labels' => false,
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
        'name' => 'Preview Port App',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'fqdn' => null,
        'redirect' => 'both',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000,8080',
        'is_http_basic_auth_enabled' => false,
    ]);
});

function createPreviewForPortTests(Application $application, int $pullRequestId, array $attributes = []): ApplicationPreview
{
    return ApplicationPreview::create(array_merge([
        'application_id' => $application->id,
        'pull_request_id' => $pullRequestId,
        'pull_request_html_url' => "https://github.com/coollabsio/coolify/pull/{$pullRequestId}",
    ], $attributes));
}

it('saves preview domain port overrides separately from the public FQDN', function () {
    $preview = createPreviewForPortTests($this->application, 101);

    $preview->update([
        'fqdn' => 'https://one-pr-101.example.com:3000,https://two-pr-101.example.com:8080',
    ]);

    $preview->refresh();

    expect($preview->fqdn)
        ->toBe('https://one-pr-101.example.com,https://two-pr-101.example.com')
        ->and($preview->domain_port_overrides)
        ->toBe([
            'https://one-pr-101.example.com' => 3000,
            'https://two-pr-101.example.com' => 8080,
        ]);
});

it('retains an existing preview port override when saving a portless domain', function () {
    $preview = createPreviewForPortTests($this->application, 102, [
        'fqdn' => 'https://one-pr-102.example.com:3000',
    ]);

    $preview->update([
        'fqdn' => 'https://one-pr-102.example.com',
    ]);

    expect($preview->fresh()->fqdn)
        ->toBe('https://one-pr-102.example.com')
        ->and($preview->fresh()->domain_port_overrides)
        ->toBe(['https://one-pr-102.example.com' => 3000]);
});

it('saves a preview port override from the add-domain form', function () {
    $preview = createPreviewForPortTests($this->application, 103);

    Livewire::test(PreviewDomains::class, ['preview' => $preview])
        ->set('newDomainParts.host', 'preview.example.com')
        ->set('newDomainParts.port', '8080')
        ->call('addDomain')
        ->assertHasNoErrors()
        ->assertDispatched('success')
        ->assertSee('Internal port 8080')
        ->assertDontSee('https://preview.example.com:8080');

    $preview->refresh();

    expect($preview->fqdn)
        ->toBe('https://preview.example.com')
        ->and($preview->domain_port_overrides['https://preview.example.com'] ?? null)
        ->toBe(8080);
});

it('retains different port overrides for two preview domains', function () {
    $preview = createPreviewForPortTests($this->application, 104);

    Livewire::test(PreviewDomains::class, ['preview' => $preview])
        ->set('newDomainParts.host', 'one-preview.example.com')
        ->set('newDomainParts.port', '3000')
        ->call('addDomain')
        ->assertHasNoErrors()
        ->set('newDomainParts.host', 'two-preview.example.com')
        ->set('newDomainParts.port', '8080')
        ->call('addDomain')
        ->assertHasNoErrors();

    $preview->refresh();

    expect($preview->domain_port_overrides['https://one-preview.example.com'] ?? null)->toBe(3000)
        ->and($preview->domain_port_overrides['https://two-preview.example.com'] ?? null)->toBe(8080)
        ->and(explode(',', (string) $preview->fqdn))
        ->toContain('https://one-preview.example.com')
        ->toContain('https://two-preview.example.com')
        ->not->toContain('https://one-preview.example.com:3000')
        ->not->toContain('https://two-preview.example.com:8080');
});

it('reopens preview domain edit with the saved port override', function () {
    $preview = createPreviewForPortTests($this->application, 105, [
        'fqdn' => 'https://preview.example.com:8080',
    ]);

    Livewire::test(PreviewDomains::class, ['preview' => $preview])
        ->assertSet('domainRows.0.url', 'https://preview.example.com')
        ->assertSet('domainRows.0.internal_port', 8080)
        ->assertSet('domainRows.0.has_port_override', true)
        ->call('startEdit', 0)
        ->assertSet('editingDomainParts.port', '8080')
        ->assertSet('editingDomainParts.host', 'preview.example.com');
});

it('does not prefill the default internal port when editing a preview domain without an override', function () {
    $preview = createPreviewForPortTests($this->application, 106, [
        'fqdn' => 'https://preview.example.com',
        'domain_port_overrides' => null,
    ]);

    Livewire::test(PreviewDomains::class, ['preview' => $preview])
        ->assertSet('domainRows.0.internal_port', 3000)
        ->assertSet('domainRows.0.has_port_override', false)
        ->call('startEdit', 0)
        ->assertSet('editingDomainParts.port', '');
});

it('clears a preview domain port override and shows the default internal port', function () {
    $preview = createPreviewForPortTests($this->application, 107, [
        'fqdn' => 'https://one-preview.example.com:8080,https://two-preview.example.com:9090',
    ]);

    Livewire::test(PreviewDomains::class, ['preview' => $preview])
        ->assertSee('Internal port 8080')
        ->call('startEdit', 0)
        ->assertSet('editingDomainParts.port', '8080')
        ->set('editingDomainParts.port', '')
        ->call('updateDomain')
        ->assertHasNoErrors()
        ->assertSee('Internal port 3000')
        ->assertSee('Internal port 9090')
        ->assertDontSee('Internal port 8080');

    $preview->refresh();

    expect($preview->fqdn)
        ->toContain('https://one-preview.example.com')
        ->and($preview->domain_port_overrides)
        ->not->toHaveKey('https://one-preview.example.com')
        ->toHaveKey('https://two-preview.example.com', 9090);
});

it('prunes a preview domain port override when removing the domain', function () {
    $preview = createPreviewForPortTests($this->application, 108, [
        'fqdn' => 'https://one-preview.example.com:8080,https://two-preview.example.com:3000',
    ]);

    Livewire::test(PreviewDomains::class, ['preview' => $preview])
        ->call('removeDomain', 0)
        ->assertDispatched('success');

    $preview->refresh();

    expect($preview->fqdn)->toBe('https://two-preview.example.com')
        ->and($preview->domain_port_overrides)
        ->toBe(['https://two-preview.example.com' => 3000]);
});

it('shows a warning when a preview domain has no internal port and ports exposes is empty', function () {
    $this->application->update([
        'ports_exposes' => null,
    ]);

    $preview = createPreviewForPortTests($this->application, 109, [
        'fqdn' => 'https://preview.example.com',
        'domain_port_overrides' => null,
    ]);

    Livewire::test(PreviewDomains::class, ['preview' => $preview])
        ->assertSee('aria-label="No internal port"', false)
        ->assertSee('Set Ports Exposes or a per-domain internal port')
        ->assertDontSee('aria-label="Internal port ', false);
});

it('rejects adding a preview domain whose portless URL is already configured', function () {
    $preview = createPreviewForPortTests($this->application, 110, [
        'fqdn' => 'https://preview.example.com:3000',
    ]);

    Livewire::test(PreviewDomains::class, ['preview' => $preview])
        ->set('newDomainParts.host', 'preview.example.com')
        ->set('newDomainParts.port', '8080')
        ->call('addDomain')
        ->assertHasErrors('newDomainParts.host');

    expect($preview->fresh()->fqdn)->toBe('https://preview.example.com')
        ->and($preview->fresh()->domain_port_overrides)
        ->toBe(['https://preview.example.com' => 3000]);
});

it('saves compose preview domain port overrides per service without putting the port in the public URL', function () {
    $this->application->update([
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n    expose: [8080]\n  api:\n    image: nginx:alpine\n    expose: [3000]\n",
        'docker_compose_domains' => null,
    ]);

    $preview = createPreviewForPortTests($this->application, 111);

    Livewire::test(PreviewDomains::class, ['preview' => $preview])
        ->set('newDomainService', 'web')
        ->set('newDomainParts.host', 'web-preview.example.com')
        ->set('newDomainParts.port', '8080')
        ->call('addDomain')
        ->assertHasNoErrors()
        ->set('newDomainService', 'api')
        ->set('newDomainParts.host', 'api-preview.example.com')
        ->set('newDomainParts.port', '3000')
        ->call('addDomain')
        ->assertHasNoErrors();

    $preview->refresh();
    $composeDomains = json_decode($preview->docker_compose_domains, true);

    expect(data_get($composeDomains, 'web.domain'))->toBe('https://web-preview.example.com')
        ->and(data_get($composeDomains, 'api.domain'))->toBe('https://api-preview.example.com')
        ->and($preview->domain_port_overrides)
        ->toBe([
            'https://web-preview.example.com' => 8080,
            'https://api-preview.example.com' => 3000,
        ]);
});

it('copies the parent domain port override onto a generated preview domain', function () {
    $this->application->update([
        'fqdn' => 'https://app.example.com:8080',
    ]);

    $preview = createPreviewForPortTests($this->application, 112);
    $preview->generate_preview_fqdn();
    $preview->refresh();

    expect($preview->fqdn)
        ->toContain('112.')
        ->not->toContain(':8080')
        ->and($preview->domain_port_overrides)
        ->toHaveCount(1)
        ->and(array_values($preview->domain_port_overrides))
        ->toBe([8080]);
});

it('saves generated preview domains once in the application parser', function () {
    $parser = file_get_contents(base_path('bootstrap/helpers/parsers.php'));
    $previewGeneration = Str::of($parser)
        ->after('// If the domain is set, we need to generate the FQDNs for the preview')
        ->before('$defaultLabels = defaultLabels');

    expect($previewGeneration->substrCount('$preview->save();'))->toBe(1)
        ->and((string) $previewGeneration)->toContain('$preview->fqdn = $fqdns->implode(\',\');');
});

it('keeps every generated preview domain port override in the legacy compose parser', function () {
    $this->application->update([
        'build_pack' => 'dockercompose',
        'compose_parsing_version' => '2',
        'docker_compose_raw' => <<<'YAML'
services:
  frontend:
    image: nginx:alpine
YAML,
        'docker_compose_domains' => json_encode([
            'frontend' => ['domain' => 'https://one.example.com:3000,https://two.example.com:8080'],
        ]),
    ]);

    $preview = createPreviewForPortTests($this->application, 124);

    parseDockerComposeFile($this->application->fresh(), pull_request_id: 124, preview_id: $preview->id);

    $preview->refresh();

    $previewDomains = explode(',', (string) $preview->fqdn);

    expect($previewDomains)->toHaveCount(2)
        ->and(collect($previewDomains)
            ->filter(fn (string $domain): bool => parse_url($domain, PHP_URL_PORT) !== null))
        ->toBeEmpty()
        ->and($preview->domain_port_overrides)->toHaveCount(2)
        ->and(array_keys($preview->domain_port_overrides))->toBe($previewDomains)
        ->and(array_values($preview->domain_port_overrides))->toBe([3000, 8080]);
});

it('finds the legacy compose preview by pull request when its id is unavailable', function (?int $previewId) {
    $this->application->update([
        'build_pack' => 'dockercompose',
        'compose_parsing_version' => '2',
        'docker_compose_raw' => "services:\n  frontend:\n    image: nginx:alpine\n",
        'docker_compose_domains' => json_encode([
            'frontend' => ['domain' => 'https://app.example.com:3000'],
        ]),
    ]);

    $preview = createPreviewForPortTests($this->application, 125);

    parseDockerComposeFile($this->application->fresh(), pull_request_id: 125, preview_id: $previewId);

    expect($preview->fresh()->fqdn)->not->toBeNull();
})->with([
    'missing id' => null,
    'stale id' => PHP_INT_MAX,
]);

it('throws a controlled exception when the legacy compose preview does not exist', function () {
    $this->application->update([
        'build_pack' => 'dockercompose',
        'compose_parsing_version' => '2',
        'docker_compose_raw' => "services:\n  frontend:\n    image: nginx:alpine\n",
        'docker_compose_domains' => json_encode([
            'frontend' => ['domain' => 'https://app.example.com:3000'],
        ]),
    ]);

    expect(fn () => parseDockerComposeFile(
        $this->application->fresh(),
        pull_request_id: 126,
        preview_id: PHP_INT_MAX,
    ))->toThrow(RuntimeException::class, 'Preview not found.');
});

it('preserves an existing preview port override in the legacy compose parser', function () {
    $this->application->update([
        'build_pack' => 'dockercompose',
        'compose_parsing_version' => '2',
        'docker_compose_raw' => <<<'YAML'
services:
  frontend:
    image: nginx:alpine
YAML,
        'docker_compose_domains' => json_encode([
            'frontend' => ['domain' => 'https://frontend.example.com'],
        ]),
    ]);

    $preview = createPreviewForPortTests($this->application, 126, [
        'fqdn' => 'https://126.frontend.example.com',
        'domain_port_overrides' => [
            'https://126.frontend.example.com' => 8080,
        ],
    ]);

    parseDockerComposeFile($this->application->fresh(), pull_request_id: 126, preview_id: $preview->id);

    expect($preview->fresh()->domain_port_overrides)
        ->toBe(['https://126.frontend.example.com' => 8080]);
});

it('does not copy production domain port overrides onto preview proxy labels', function () {
    $this->application->update([
        'fqdn' => 'https://app.example.com',
        'domain_port_overrides' => [
            'https://app.example.com' => 9090,
        ],
    ]);

    $preview = createPreviewForPortTests($this->application, 113, [
        'fqdn' => 'https://113.app.example.com',
        'domain_port_overrides' => null,
    ]);

    $labels = collect(generateLabelsApplication($this->application->fresh(), $preview->fresh()));

    expect($labels)
        ->toContain('traefik.http.services.https-0-'.$this->application->uuid.'-pr-113.loadbalancer.server.port=3000')
        ->not->toContain('loadbalancer.server.port=9090');
});

it('routes portless preview domains to saved preview internal port overrides', function () {
    $preview = createPreviewForPortTests($this->application, 114, [
        'fqdn' => 'https://one-pr.example.com,https://two-pr.example.com',
        'domain_port_overrides' => [
            'https://one-pr.example.com' => 3000,
            'https://two-pr.example.com' => 8080,
        ],
    ]);

    $labels = collect(generateLabelsApplication($this->application->fresh(), $preview->fresh()));
    $uuid = $this->application->uuid.'-pr-114';

    expect($labels)
        ->toContain('traefik.http.routers.https-0-'.$uuid.'.rule=Host(`one-pr.example.com`) && PathPrefix(`/`)')
        ->toContain('traefik.http.services.https-0-'.$uuid.'.loadbalancer.server.port=3000')
        ->toContain('traefik.http.routers.https-1-'.$uuid.'.rule=Host(`two-pr.example.com`) && PathPrefix(`/`)')
        ->toContain('traefik.http.services.https-1-'.$uuid.'.loadbalancer.server.port=8080')
        ->toContain('caddy_0.handle_path.0_reverse_proxy={{upstreams 3000}}')
        ->toContain('caddy_1.handle_path.1_reverse_proxy={{upstreams 8080}}')
        ->not->toContain('Host(`one-pr.example.com:3000`)')
        ->not->toContain('Host(`two-pr.example.com:8080`)');
});

it('uses the first ports_exposes value for a portless preview domain without an override', function () {
    $preview = createPreviewForPortTests($this->application, 115, [
        'fqdn' => 'https://plain-pr.example.com',
        'domain_port_overrides' => null,
    ]);

    $labels = collect(generateLabelsApplication($this->application->fresh(), $preview->fresh()));

    expect($labels)
        ->toContain('traefik.http.services.https-0-'.$this->application->uuid.'-pr-115.loadbalancer.server.port=3000')
        ->toContain('caddy_0.handle_path.0_reverse_proxy={{upstreams 3000}}');
});

it('keeps routing a legacy port-bearing preview FQDN without an override map', function () {
    $preview = createPreviewForPortTests($this->application, 116, [
        'fqdn' => 'https://legacy-pr.example.com',
    ]);

    DB::table('application_previews')->where('id', $preview->id)->update([
        'fqdn' => 'https://legacy-pr.example.com:9090',
        'domain_port_overrides' => null,
    ]);

    $labels = collect(generateLabelsApplication($this->application->fresh(), $preview->fresh()));

    expect($labels)
        ->toContain('traefik.http.routers.https-0-'.$this->application->uuid.'-pr-116.rule=Host(`legacy-pr.example.com`) && PathPrefix(`/`)')
        ->toContain('traefik.http.services.https-0-'.$this->application->uuid.'-pr-116.loadbalancer.server.port=9090')
        ->toContain('caddy_0.handle_path.0_reverse_proxy={{upstreams 9090}}');
});

it('passes preview domain port overrides into compose pull-request labels', function () {
    $this->application->update([
        'build_pack' => 'dockercompose',
        'compose_parsing_version' => '3',
        'docker_compose_raw' => <<<'YAML'
services:
  frontend:
    image: myapp/frontend:latest
YAML,
        'fqdn' => null,
        'docker_compose_domains' => json_encode([
            'frontend' => ['domain' => 'https://frontend.example.com'],
        ]),
        'domain_port_overrides' => [
            'https://frontend.example.com' => 80,
        ],
    ]);

    $preview = createPreviewForPortTests($this->application, 117, [
        'docker_compose_domains' => json_encode([
            'frontend' => ['domain' => 'https://117.frontend.example.com'],
        ]),
        'fqdn' => 'https://117.frontend.example.com',
        'domain_port_overrides' => [
            'https://117.frontend.example.com' => 8080,
        ],
    ]);

    $parsedCompose = applicationParser($this->application->fresh(), 117, $preview->id);
    $labels = collect(data_get($parsedCompose, 'services.frontend-pr-117.labels'));

    expect($labels->contains(fn (string $label): bool => str_ends_with($label, '.loadbalancer.server.port=8080')))
        ->toBeTrue()
        ->and($labels->contains(fn (string $label): bool => str_contains($label, 'reverse_proxy={{upstreams 8080}}')))
        ->toBeTrue()
        ->and($labels->contains(fn (string $label): bool => str_contains($label, 'Host(`117.frontend.example.com`)')))
        ->toBeTrue()
        ->and($labels->contains(fn (string $label): bool => str_ends_with($label, '.loadbalancer.server.port=80')))
        ->toBeFalse();
});

it('leaves single-service compose preview ports to proxy discovery when a domain has no override', function () {
    $this->application->update([
        'build_pack' => 'dockercompose',
        'compose_parsing_version' => '3',
        'ports_exposes' => '4000,5000',
        'docker_compose_raw' => <<<'YAML'
services:
  frontend:
    image: myapp/frontend:latest
YAML,
        'fqdn' => null,
        'domain_port_overrides' => null,
        'docker_compose_domains' => json_encode([
            'frontend' => ['domain' => 'https://frontend.example.com'],
        ]),
    ]);

    $preview = createPreviewForPortTests($this->application, 118, [
        'docker_compose_domains' => json_encode([
            'frontend' => ['domain' => 'https://118.frontend.example.com'],
        ]),
        'fqdn' => 'https://118.frontend.example.com',
        'domain_port_overrides' => null,
    ]);

    $parsedCompose = applicationParser($this->application->fresh(), 118, $preview->id);
    $labels = collect(data_get($parsedCompose, 'services.frontend-pr-118.labels'));

    expect($labels->contains(fn (string $label): bool => str_contains($label, '.loadbalancer.server.port=')))
        ->toBeFalse()
        ->and($labels->contains(fn (string $label): bool => str_contains($label, 'reverse_proxy={{upstreams}}')))
        ->toBeTrue();
});

it('shows a port warning when a preview domain uses a port that is not exposed or used by the application', function () {
    $preview = createPreviewForPortTests($this->application, 119);

    Livewire::test(PreviewDomains::class, ['preview' => $preview])
        ->set('newDomainParts.host', 'preview.example.com')
        ->set('newDomainParts.port', '9090')
        ->call('addDomain')
        ->assertSet('showPortWarningModal', true)
        ->assertSet('unrecognizedPort', 9090)
        ->assertSee('Use a different port?')
        ->assertSee('9090');

    expect($preview->fresh()->fqdn)->toBeNull();
});

it('does not warn when a preview domain port is listed in ports exposes', function () {
    $preview = createPreviewForPortTests($this->application, 120);

    Livewire::test(PreviewDomains::class, ['preview' => $preview])
        ->set('newDomainParts.host', 'preview.example.com')
        ->set('newDomainParts.port', '8080')
        ->call('addDomain')
        ->assertSet('showPortWarningModal', false)
        ->assertDispatched('success');
});

it('does not warn when a preview domain port is already used by an application domain', function () {
    $this->application->update([
        'ports_exposes' => '3000',
        'fqdn' => 'https://app.example.com:9090',
    ]);

    $preview = createPreviewForPortTests($this->application, 121);

    Livewire::test(PreviewDomains::class, ['preview' => $preview])
        ->set('newDomainParts.host', 'preview.example.com')
        ->set('newDomainParts.port', '9090')
        ->call('addDomain')
        ->assertSet('showPortWarningModal', false)
        ->assertDispatched('success');
});

it('saves an unrecognized preview domain port after confirming the warning', function () {
    $preview = createPreviewForPortTests($this->application, 122);

    Livewire::test(PreviewDomains::class, ['preview' => $preview])
        ->set('newDomainParts.host', 'preview.example.com')
        ->set('newDomainParts.port', '9090')
        ->call('addDomain')
        ->assertSet('showPortWarningModal', true)
        ->call('confirmUseUnknownPort')
        ->assertSet('showPortWarningModal', false)
        ->assertDispatched('success');

    expect($preview->fresh()->fqdn)->toBe('https://preview.example.com')
        ->and($preview->fresh()->domain_port_overrides)
        ->toBe(['https://preview.example.com' => 9090]);
});

it('cancels an unrecognized preview domain port without saving', function () {
    $preview = createPreviewForPortTests($this->application, 123);

    Livewire::test(PreviewDomains::class, ['preview' => $preview])
        ->set('newDomainParts.host', 'preview.example.com')
        ->set('newDomainParts.port', '9090')
        ->call('addDomain')
        ->assertSet('showPortWarningModal', true)
        ->call('cancelUseUnknownPort')
        ->assertSet('showPortWarningModal', false);

    expect($preview->fresh()->fqdn)->toBeNull();
});

it('warns when editing a preview domain to an unrecognized port', function () {
    $preview = createPreviewForPortTests($this->application, 124, [
        'fqdn' => 'https://preview.example.com:3000',
    ]);

    Livewire::test(PreviewDomains::class, ['preview' => $preview])
        ->call('startEdit', 0)
        ->set('editingDomainParts.port', '5555')
        ->call('updateDomain')
        ->assertSet('showPortWarningModal', true)
        ->assertSet('unrecognizedPort', 5555);

    expect($preview->fresh()->domain_port_overrides)
        ->toBe(['https://preview.example.com' => 3000]);
});

it('does not warn when re-saving a preview domain with the same custom port', function () {
    $preview = createPreviewForPortTests($this->application, 125, [
        'fqdn' => 'https://preview.example.com:9090',
    ]);

    Livewire::test(PreviewDomains::class, ['preview' => $preview])
        ->call('startEdit', 0)
        ->set('editingDomainParts.port', '9090')
        ->call('updateDomain')
        ->assertSet('showPortWarningModal', false)
        ->assertDispatched('success');
});

it('checks compose preview ports against the selected service when saving domains', function (string $action, int $port, bool $warn) {
    $this->application->update([
        'build_pack' => 'dockercompose',
        'ports_exposes' => '3000',
        'docker_compose_raw' => <<<'YAML'
services:
  web:
    image: nginx:alpine
    expose:
      - 8080
      - 8081
    ports:
      - target: 8082
        published: 18082
      - "18083:8083"
  api:
    image: nginx:alpine
    expose:
      - 9090
YAML,
        'docker_compose_domains' => json_encode([
            'web' => ['domain' => 'https://existing.example.com'],
            'api' => ['domain' => 'https://api.example.com:9090'],
        ]),
    ]);
    $preview = createPreviewForPortTests($this->application, 130, [
        'docker_compose_domains' => json_encode(['web' => ['domain' => 'https://existing.example.com']]),
        'fqdn' => 'https://existing.example.com',
    ]);

    $component = Livewire::test(PreviewDomains::class, ['preview' => $preview]);
    if ($action === 'add') {
        $component->set('newDomainService', 'web')
            ->set('newDomainParts.host', 'new.example.com')
            ->set('newDomainParts.port', (string) $port)
            ->call('addDomain');
        $domain = 'https://new.example.com';
    } else {
        $component->call('startEdit', 0)
            ->set('editingDomainParts.port', (string) $port)
            ->call('updateDomain');
        $domain = 'https://existing.example.com';
    }

    $component->assertHasNoErrors()->assertSet('showPortWarningModal', $warn);

    if ($warn) {
        expect($preview->fresh()->domain_port_overrides ?? [])->not->toHaveKey($domain);
        $component->assertSet('unrecognizedPort', $port)
            ->call('confirmUseUnknownPort')
            ->assertSet('showPortWarningModal', false);
    }

    $component->assertDispatched('success');
    expect($preview->fresh()->domain_port_overrides[$domain] ?? null)->toBe($port);
})->with(['add', 'edit'])->with([
    'first exposed port' => [8080, false],
    'second exposed port' => [8081, false],
    'long syntax target' => [8082, false],
    'short syntax target' => [8083, false],
    'global port' => [3000, true],
    'another service port' => [9090, true],
    'published host port' => [18082, true],
]);

it('keeps an existing custom compose preview port without another warning', function () {
    $this->application->update([
        'build_pack' => 'dockercompose',
        'ports_exposes' => '3000',
        'docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n    expose: [8080]\n",
        'docker_compose_domains' => json_encode(['web' => ['domain' => 'https://existing.example.com:7070']]),
    ]);
    $preview = createPreviewForPortTests($this->application, 131, [
        'docker_compose_domains' => json_encode(['web' => ['domain' => 'https://existing.example.com:7070']]),
        'fqdn' => 'https://existing.example.com:7070',
    ]);

    Livewire::test(PreviewDomains::class, ['preview' => $preview])
        ->call('startEdit', 0)
        ->assertSet('editingDomainParts.port', '7070')
        ->call('updateDomain')
        ->assertHasNoErrors()
        ->assertSet('showPortWarningModal', false)
        ->assertDispatched('success');

    expect($preview->fresh()->domain_port_overrides['https://existing.example.com'] ?? null)->toBe(7070);
});

it('keeps the selected preview domain identity across polling refreshes', function (bool $compose) {
    $first = 'https://first-preview.example.com';
    $second = 'https://second-preview.example.com';
    if ($compose) {
        $this->application->update([
            'build_pack' => 'dockercompose',
            'docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n  api:\n    image: nginx:alpine\n",
        ]);
    }
    $preview = createPreviewForPortTests($this->application, 140, $compose ? [
        'docker_compose_domains' => json_encode(['web' => ['domain' => $first], 'api' => ['domain' => $first]]),
    ] : ['fqdn' => "$first,$second"]);
    $component = Livewire::test(PreviewDomains::class, ['preview' => $preview])
        ->call('startEdit', 0)
        ->set('editingDomainParts.host', 'renamed-preview.example.com');

    $preview->update($compose ? [
        'docker_compose_domains' => json_encode(['api' => ['domain' => $first], 'web' => ['domain' => $first]]),
    ] : ['fqdn' => "$second,$first"]);

    $component->call('pollDnsChecks')
        ->assertSet('editingIndex', 1)
        ->assertSet('editingDomainParts.host', 'renamed-preview.example.com')
        ->call('updateDomain')
        ->assertHasNoErrors();

    $preview->refresh();
    if ($compose) {
        expect(json_decode($preview->docker_compose_domains, true))
            ->toMatchArray(['web' => ['domain' => 'https://renamed-preview.example.com'], 'api' => ['domain' => $first]]);
    } else {
        expect($preview->fqdn)->toBe("$second,https://renamed-preview.example.com");
    }
})->with([false, true]);

it('keeps the selected preview domain when deleting an earlier row', function () {
    $preview = createPreviewForPortTests($this->application, 141, [
        'fqdn' => 'https://first-preview.example.com,https://second-preview.example.com',
    ]);

    Livewire::test(PreviewDomains::class, ['preview' => $preview])
        ->call('startEdit', 1)
        ->call('removeDomainByKey', hash('sha256', 'https://first-preview.example.com|'))
        ->assertSet('editingIndex', 0)
        ->set('editingDomainParts.host', 'renamed-preview.example.com')
        ->call('updateDomain')
        ->assertHasNoErrors();

    expect($preview->fresh()->fqdn)->toBe('https://renamed-preview.example.com');
});

it('clears the selected preview domain when it is deleted', function () {
    $preview = createPreviewForPortTests($this->application, 142, [
        'fqdn' => 'https://first-preview.example.com,https://second-preview.example.com',
    ]);

    Livewire::test(PreviewDomains::class, ['preview' => $preview])
        ->call('startEdit', 0)
        ->call('removeDomain', 0)
        ->assertSet('editingIndex', null)
        ->assertDispatched('close-preview-domain-edit', previewId: $preview->id)
        ->set('editingDomainParts.host', 'wrong-preview.example.com')
        ->call('updateDomain');

    expect($preview->fresh()->fqdn)->toBe('https://second-preview.example.com');
});

it('scopes preview domain modal events to their preview', function () {
    $preview = createPreviewForPortTests($this->application, 143, ['fqdn' => 'https://preview.example.com']);

    Livewire::test(PreviewDomains::class, ['preview' => $preview])
        ->call('startEdit', 0)
        ->assertDispatched('open-preview-domain-edit', previewId: $preview->id)
        ->set('editingDomainParts.host', 'renamed-preview.example.com')
        ->call('updateDomain')
        ->assertDispatched('close-preview-domain-edit', previewId: $preview->id)
        ->set('newDomainParts.host', 'another-preview.example.com')
        ->call('addDomain')
        ->assertDispatched('close-preview-domain-add', previewId: $preview->id)
        ->assertNotDispatched('close-modal');
});

it('prevents members from opening editable preview domain settings', function () {
    $preview = createPreviewForPortTests($this->application, 144, ['fqdn' => 'https://preview.example.com']);
    $this->team->members()->updateExistingPivot($this->user->id, ['role' => 'member']);
    $this->actingAs($this->user->fresh());

    Livewire::test(PreviewDomains::class, ['preview' => $preview])
        ->assertSuccessful()
        ->call('startEdit', 0)
        ->assertForbidden();
});

it('prevents reading preview domains from another team', function () {
    $preview = createPreviewForPortTests($this->application, 145, ['fqdn' => 'https://private-preview.example.com']);
    $otherTeam = Team::factory()->create();
    $otherUser = User::factory()->create();
    $otherTeam->members()->attach($otherUser->id, ['role' => 'owner']);
    $this->actingAs($otherUser);
    session(['currentTeam' => $otherTeam]);

    Livewire::test(PreviewDomains::class, ['preview' => $preview])->assertForbidden();
});

it('rechecks preview view permission before polling domains', function () {
    $preview = createPreviewForPortTests($this->application, 146, ['fqdn' => 'https://private-preview.example.com']);
    $component = Livewire::test(PreviewDomains::class, ['preview' => $preview]);
    $this->team->members()->detach($this->user->id);
    $this->actingAs($this->user->fresh());

    $component->call('pollDnsChecks')->assertForbidden();
});

it('reports the readonly preview redirect policy used by proxy labels', function (int $parserVersion, string $expectedRedirect) {
    $compose = $parserVersion > 0;
    $this->application->update(array_merge(['redirect' => 'www'], $compose ? [
        'build_pack' => 'dockercompose',
        'compose_parsing_version' => (string) $parserVersion,
        'docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n    expose: [3000]\n",
        'docker_compose_domains' => json_encode(['web' => ['domain' => 'https://production.example.com', 'redirect' => 'www']]),
    ] : []));
    $previewDomain = $expectedRedirect === 'non-www' ? 'https://www.preview.example.com' : 'https://preview.example.com';
    $preview = createPreviewForPortTests($this->application, 147, array_merge([
        'fqdn' => $previewDomain,
    ], $compose ? [
        'docker_compose_domains' => json_encode(['web' => [
            'domain' => $previewDomain,
            'redirect' => 'non-www',
        ]]),
    ] : []));

    Livewire::test(PreviewDomains::class, ['preview' => $preview])
        ->assertSet('domainRows.0.redirect', $expectedRedirect);

    $labels = $compose
        ? collect(data_get($this->application->fresh()->parse(147, $preview->id), 'services.web-pr-147.labels'))->implode("\n")
        : implode("\n", generateLabelsApplication($this->application->fresh(), $preview->fresh()));
    expect($labels)->toContain('X-Robots-Tag=noindex, nofollow');
    if ($expectedRedirect === 'both') {
        expect($labels)->not->toContain('.redirectregex.');
    } else {
        expect($labels)->toContain('.redirectregex.');
    }
})->with([
    'normal previews ignore the production redirect' => [0, 'both'],
    'legacy compose previews use the production redirect' => [2, 'www'],
    'current compose previews use their own stored redirect' => [3, 'non-www'],
]);

it('clears the previous preview address validation error when opening another domain', function () {
    $preview = createPreviewForPortTests($this->application, 148, [
        'fqdn' => 'https://first-preview.example.com,https://second-preview.example.com',
    ]);

    Livewire::test(PreviewDomains::class, ['preview' => $preview])
        ->call('startEdit', 0)
        ->set('editingDomainParts.host', 'not a hostname')
        ->call('updateDomain')
        ->assertHasErrors('editingDomainParts.host')
        ->call('startEdit', 1)
        ->assertHasNoErrors('editingDomainParts.host');
});

it('inherits preview HTTPS redirect policy while always preventing indexing', function (bool $compose, bool $forceHttps) {
    if ($compose) {
        $this->application->update([
            'build_pack' => 'dockercompose',
            'compose_parsing_version' => '3',
            'docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n    expose: [3000]\n",
            'docker_compose_domains' => json_encode(['web' => ['domain' => 'https://production.example.com']]),
        ]);
    }
    $this->application->settings()->update(['is_force_https_enabled' => $forceHttps]);
    $preview = createPreviewForPortTests($this->application, 149, array_merge([
        'fqdn' => 'https://preview.example.com',
    ], $compose ? [
        'docker_compose_domains' => json_encode(['web' => ['domain' => 'https://preview.example.com']]),
    ] : []));

    $labels = $compose
        ? collect(data_get($this->application->fresh()->parse(149, $preview->id), 'services.web-pr-149.labels'))->implode("\n")
        : implode("\n", generateLabelsApplication($this->application->fresh(), $preview->fresh()));

    expect($labels)->toContain('X-Robots-Tag=noindex, nofollow');
    expect((bool) preg_match('/\.middlewares=[^\n]*redirect-to-https/', $labels))->toBe($forceHttps);
})->with([false, true])->with([false, true]);

it('preserves readonly compose preview redirects when saving an address', function () {
    $this->application->update([
        'build_pack' => 'dockercompose',
        'compose_parsing_version' => '3',
        'docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n  api:\n    image: nginx:alpine\n",
    ]);
    $preview = createPreviewForPortTests($this->application, 150, [
        'docker_compose_domains' => json_encode([
            'web' => ['domain' => 'https://www.preview.example.com', 'redirect' => 'non-www'],
            'api' => ['domain' => '', 'redirect' => 'www'],
        ]),
    ]);

    Livewire::test(PreviewDomains::class, ['preview' => $preview])
        ->assertSet('domainRows.0.redirect', 'non-www')
        ->call('startEdit', 0)
        ->set('editingDomainParts.host', 'www.renamed-preview.example.com')
        ->call('updateDomain')
        ->assertHasNoErrors()
        ->assertSet('domainRows.0.redirect', 'non-www');

    expect(json_decode($preview->fresh()->docker_compose_domains, true))->toBe([
        'web' => ['domain' => 'https://www.renamed-preview.example.com', 'redirect' => 'non-www'],
        'api' => ['domain' => '', 'redirect' => 'www'],
    ]);
});
