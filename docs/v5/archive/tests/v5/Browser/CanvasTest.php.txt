<?php

use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\User;
use App\Models\V5\Application as V5Application;
use App\Models\V5\ApplicationDomain as V5ApplicationDomain;
use App\Models\V5\Server as V5Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Config::set('broadcasting.default', 'log');
    InstanceSettings::create(['id' => 0, 'is_sponsorship_popup_enabled' => false]);

    $this->user = User::factory()->create([
        'name' => 'Root User',
        'email' => 'test@example.com',
        'password' => Hash::make('password'),
    ]);
    $this->team = $this->user->teams()->firstOrFail();
    $this->project = Project::create([
        'name' => 'Canvas Project',
        'team_id' => $this->team->id,
    ]);
    $this->environment = $this->project->environments()->firstOrFail();
});

function createCanvasV5Server(array $attributes = []): V5Server
{
    return V5Server::query()->create([
        'team_id' => test()->team->id,
        'created_by_user_id' => test()->user->id,
        'name' => 'edge-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'last_bootstrapped_at' => now(),
        ...$attributes,
    ]);
}

function createCanvasV5Application(V5Server $server, array $attributes = []): V5Application
{
    return V5Application::query()->create([
        'team_id' => test()->team->id,
        'project_id' => test()->project->id,
        'environment_id' => test()->environment->id,
        'server_id' => $server->id,
        'created_by_user_id' => test()->user->id,
        'name' => 'nginx-test',
        'image' => 'docker.io/library/nginx:alpine',
        'container_name' => 'coolify-v5-nginx-'.strtolower((string) Str::ulid()),
        'status' => 'running',
        'mesh_namespace' => 'default',
        'canvas_x' => 0,
        'canvas_y' => 0,
        ...$attributes,
    ]);
}

it('renders seeded applications on the canvas', function () {
    $server = createCanvasV5Server();
    createCanvasV5Application($server);

    $this->actingAs($this->user);

    $page = visit('/v5');

    $page->assertSee('nginx-test')
        ->assertSee('docker.io/library/nginx:alpine')
        ->assertSee('edge-01')
        ->assertSee('Configure')
        ->assertSee('1 apps')
        ->assertDontSee('No applications on this canvas yet.')
        ->assertNoJavaScriptErrors()
        ->screenshot();
});

it('shows the selected project and environment in the navbar', function () {
    $this->actingAs($this->user);

    $page = visit('/v5');

    $page->assertSee('Canvas Project')
        ->assertSee('production')
        ->assertNoJavaScriptErrors()
        ->screenshot();
});

it('deploys an nginx container from the toolbar', function () {
    createCanvasV5Server();
    fakeSuccessfulNginxFluxDeployment();

    $this->actingAs($this->user);

    $page = visit('/v5');

    $page->assertSee('No applications on this canvas yet.')
        ->click('Deploy')
        ->assertSee('docker.io/library/nginx:alpine')
        ->assertDontSee('No applications on this canvas yet.')
        ->assertNoJavaScriptErrors()
        ->screenshot();

    $application = V5Application::query()->sole();

    expect($application->image)->toBe('docker.io/library/nginx:alpine')
        ->and($application->status)->toBe('running');
});

it('disables app ingress from the inspector sheet', function () {
    // Server ingress on, but not "installed", so the Caddy config sync is skipped.
    $server = createCanvasV5Server(['is_ingress' => true, 'status' => 'installing']);
    $application = createCanvasV5Application($server, ['ingress_enabled' => true, 'internal_port' => 8080]);
    V5ApplicationDomain::query()->create([
        'application_id' => $application->id,
        'domain' => 'app.example.com',
    ]);

    $this->actingAs($this->user);

    $page = visit('/v5');

    $page->assertSee('nginx-test')
        ->click('Configure')
        ->assertSee('App configuration')
        ->click('Networking')
        ->assertSee('Public ingress')
        ->click('[data-slot="sheet-content"] button:has-text("Disable")')
        ->assertSee('Private')
        ->assertNoJavaScriptErrors()
        ->screenshot();

    expect($application->refresh()->ingress_enabled)->toBeFalse();
});

it('enables app ingress through the ingress dialog', function () {
    $server = createCanvasV5Server(['is_ingress' => true, 'status' => 'installing']);
    $application = createCanvasV5Application($server, ['ingress_enabled' => false]);

    $this->actingAs($this->user);

    $page = visit('/v5');

    $page->assertSee('nginx-test')
        ->click('button:has-text("Enable")')
        ->assertSee('Enable app ingress')
        ->fill('[placeholder="example.com, www.example.com"]', 'app.example.com')
        ->fill('[placeholder="3000"]', '8080')
        ->click('button:has-text("Enable ingress")')
        ->assertDontSee('Ingress update failed')
        ->assertNoJavaScriptErrors()
        ->screenshot();

    expect($application->refresh()->ingress_enabled)->toBeTrue()
        ->and($application->internal_port)->toBe(8080)
        ->and($application->domains()->pluck('domain')->all())->toBe(['app.example.com']);
});
