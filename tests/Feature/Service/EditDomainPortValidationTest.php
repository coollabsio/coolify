<?php

use App\Livewire\Project\Service\Domains;
use App\Livewire\Project\Service\EditDomain;
use App\Livewire\Project\Service\Index;
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
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    InstanceSettings::forceCreate(['id' => 0]);

    // Create user and team
    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    // Create server
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
    ]);

    // Create standalone docker destination
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();

    // Create project and environment
    $this->project = Project::factory()->create([
        'team_id' => $this->team->id,
    ]);

    $this->environment = Environment::factory()->create([
        'project_id' => $this->project->id,
    ]);

    // Create service with a name that maps to a template with required port
    $this->service = Service::factory()->create([
        'name' => 'supabase-test123',
        'server_id' => $this->server->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'environment_id' => $this->environment->id,
    ]);

    // Create service application
    $this->serviceApplication = ServiceApplication::create([
        'service_id' => $this->service->id,
        'name' => 'web',
        'image' => 'nginx:alpine',
        'fqdn' => 'http://example.com:8000',
    ]);

    // Mock get_service_templates to return a service with required port
    if (! function_exists('get_service_templates_mock')) {
        function get_service_templates_mock()
        {
            return collect([
                'supabase' => [
                    'name' => 'Supabase',
                    'port' => '8000',
                    'documentation' => 'https://supabase.com',
                ],
            ]);
        }
    }
});

it('loads a persisted port override in the service application editor', function () {
    $this->serviceApplication->update([
        'fqdn' => 'https://web.example.com',
        'domain_port_overrides' => [
            'https://web.example.com' => 8080,
        ],
    ]);

    Livewire::test(Index::class, [
        'serviceApplication' => $this->serviceApplication->fresh(),
        'parameters' => [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'service_uuid' => $this->service->uuid,
            'stack_service_uuid' => $this->serviceApplication->uuid,
        ],
        'query' => [],
    ])
        ->assertSet('fqdn', 'https://web.example.com:8080')
        ->assertOk();
});

it('initializes route state when mounting a service application directly', function () {
    Livewire::test(Index::class, [
        'serviceApplication' => $this->serviceApplication,
    ])
        ->assertSet('parameters', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'service_uuid' => $this->service->uuid,
            'stack_service_uuid' => $this->serviceApplication->uuid,
        ])
        ->assertSet('query', [])
        ->assertOk();
});

it('loads the EditDomain component with required port', function () {
    Livewire::test(EditDomain::class, ['applicationId' => $this->serviceApplication->id])
        ->assertSet('requiredPort', 8000)
        ->assertSet('fqdn', 'http://example.com:8000')
        ->assertOk();
});

it('loads a persisted port override and moves it when the hostname changes', function () {
    $this->serviceApplication->update([
        'fqdn' => 'https://old.example.com',
        'domain_port_overrides' => [
            'https://old.example.com' => 8080,
        ],
    ]);

    Livewire::test(EditDomain::class, ['applicationId' => $this->serviceApplication->id])
        ->assertSet('fqdn', 'https://old.example.com:8080')
        ->set('fqdn', 'https://new.example.com:8080')
        ->call('submit')
        ->assertSet('showPortWarningModal', false)
        ->assertSet('fqdn', 'https://new.example.com:8080');

    expect($this->serviceApplication->fresh())
        ->fqdn->toBe('https://new.example.com')
        ->domain_port_overrides->toBe([
            'https://new.example.com' => 8080,
        ]);
});

it('marks noindex changes as pending configuration', function () {
    $this->service->isConfigurationChanged(save: true);

    Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])])
        ->call('toggleNoindexDomain', $this->serviceApplication->id, 'http://example.com:8000', true)
        ->assertDispatched('configurationChanged');

    expect($this->service->refresh()->isConfigurationChanged())->toBeTrue();
});

it('shows warning modal when trying to remove required port', function () {
    Livewire::test(EditDomain::class, ['applicationId' => $this->serviceApplication->id])
        ->set('fqdn', 'http://example.com') // Remove port
        ->call('submit')
        ->assertSet('showPortWarningModal', true)
        ->assertSet('requiredPort', 8000);
});

it('allows port removal when user confirms', function () {
    Livewire::test(EditDomain::class, ['applicationId' => $this->serviceApplication->id])
        ->set('fqdn', 'http://example.com') // Remove port
        ->call('submit')
        ->assertSet('showPortWarningModal', true)
        ->call('confirmRemovePort')
        ->assertSet('showPortWarningModal', false);

    // Verify the FQDN was updated in database
    $this->serviceApplication->refresh();
    expect($this->serviceApplication->fqdn)->toBe('http://example.com');
});

it('cancels port removal when user cancels', function () {
    $originalFqdn = $this->serviceApplication->url;

    Livewire::test(EditDomain::class, ['applicationId' => $this->serviceApplication->id])
        ->set('fqdn', 'http://example.com') // Remove port
        ->call('submit')
        ->assertSet('showPortWarningModal', true)
        ->call('cancelRemovePort')
        ->assertSet('showPortWarningModal', false)
        ->assertSet('fqdn', $originalFqdn); // Should revert to original
});

it('allows saving when port is changed to different port', function () {
    Livewire::test(EditDomain::class, ['applicationId' => $this->serviceApplication->id])
        ->set('fqdn', 'http://example.com:3000') // Change to different port
        ->call('submit')
        ->assertSet('showPortWarningModal', false); // Should not show warning

    // Verify the FQDN was updated
    $this->serviceApplication->refresh();
    expect($this->serviceApplication->fqdn)->toBe('http://example.com')
        ->and($this->serviceApplication->domain_port_overrides)->toBe([
            'http://example.com' => 3000,
        ]);
});

it('allows saving when all domains have ports (multiple domains)', function () {
    Livewire::test(EditDomain::class, ['applicationId' => $this->serviceApplication->id])
        ->set('fqdn', 'http://example.com:8000,https://app.example.com:8080')
        ->call('submit')
        ->assertSet('showPortWarningModal', false); // Should not show warning
});

it('shows warning when at least one domain is missing port (multiple domains)', function () {
    Livewire::test(EditDomain::class, ['applicationId' => $this->serviceApplication->id])
        ->set('fqdn', 'http://example.com:8000,https://app.example.com') // Second domain missing port
        ->call('submit')
        ->assertSet('showPortWarningModal', true);
});

it('does not show warning for services without required port', function () {
    // Create a service without required port (e.g., cloudflared)
    $serviceWithoutPort = Service::factory()->create([
        'name' => 'cloudflared-test456',
        'server_id' => $this->server->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'environment_id' => $this->environment->id,
    ]);

    $appWithoutPort = ServiceApplication::create([
        'service_id' => $serviceWithoutPort->id,
        'name' => 'web',
        'image' => 'nginx:alpine',
        'fqdn' => 'http://example.com',
    ]);

    Livewire::test(EditDomain::class, ['applicationId' => $appWithoutPort->id])
        ->set('fqdn', 'http://example.com') // No port
        ->call('submit')
        ->assertSet('showPortWarningModal', false); // Should not show warning
});
