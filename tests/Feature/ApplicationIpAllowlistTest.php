<?php

use App\Livewire\Project\Application\General;
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
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $plainTextToken = Str::random(40);
    $token = $this->user->tokens()->create([
        'name' => 'ip-allowlist-api-test',
        'token' => hash('sha256', $plainTextToken),
        'abilities' => ['*'],
        'team_id' => $this->team->id,
    ]);
    $this->bearerToken = $token->getKey().'|'.$plainTextToken;

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->first();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
});

function ipAllowlistApplication(array $overrides = []): Application
{
    return Application::factory()->create(array_merge([
        'environment_id' => test()->environment->id,
        'destination_id' => test()->destination->id,
        'destination_type' => test()->destination->getMorphClass(),
        'build_pack' => 'nixpacks',
        'fqdn' => 'https://example.com',
        'ports_exposes' => '3000',
        'redirect' => 'both',
        'is_http_basic_auth_enabled' => false,
        'static_image' => 'nginx:alpine',
        'base_directory' => '/',
    ], $overrides));
}

test('the API stores a normalized allowlist and returns it', function () {
    $application = ipAllowlistApplication();

    $this->withToken($this->bearerToken)
        ->patchJson("/api/v1/applications/{$application->uuid}", ['ip_allowlist' => " 203.0.113.5,\n10.0.0.0/8  2001:db8::/32,203.0.113.5 "])
        ->assertOk();

    expect($application->refresh()->ip_allowlist)->toBe('203.0.113.5,10.0.0.0/8,2001:db8::/32');

    $this->withToken($this->bearerToken)
        ->getJson("/api/v1/applications/{$application->uuid}")
        ->assertOk()
        ->assertJsonPath('ip_allowlist', '203.0.113.5,10.0.0.0/8,2001:db8::/32');
});

test('the API rejects invalid allowlist entries', function () {
    $application = ipAllowlistApplication(['ip_allowlist' => '203.0.113.5']);

    $this->withToken($this->bearerToken)
        ->patchJson("/api/v1/applications/{$application->uuid}", ['ip_allowlist' => '203.0.113.5,10.0.0.0/33'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('ip_allowlist');

    expect($application->refresh()->ip_allowlist)->toBe('203.0.113.5');
});

test('the API clears the allowlist with null', function () {
    $application = ipAllowlistApplication(['ip_allowlist' => '203.0.113.5']);

    $this->withToken($this->bearerToken)
        ->patchJson("/api/v1/applications/{$application->uuid}", ['ip_allowlist' => null])
        ->assertOk();

    expect($application->refresh()->ip_allowlist)->toBeNull();
});

test('the API accepts an allowlist when it creates an application', function () {
    $response = $this->withToken($this->bearerToken)
        ->postJson('/api/v1/applications/public', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'server_uuid' => $this->server->uuid,
            'git_repository' => 'https://gitlab.com/coolify/test-static-app',
            'git_branch' => 'main',
            'build_pack' => 'nixpacks',
            'ports_exposes' => '3000',
            'ip_allowlist' => '203.0.113.5',
            'autogenerate_domain' => false,
        ]);

    $response->assertCreated();

    expect(Application::where('uuid', $response->json('uuid'))->firstOrFail()->ip_allowlist)->toBe('203.0.113.5');
});

test('the general form validates and saves the allowlist', function () {
    $this->actingAs($this->user);
    $application = ipAllowlistApplication();

    Livewire::test(General::class, ['application' => $application])
        ->set('ipAllowlist', '300.1.1.1')
        ->call('submit')
        ->assertDispatched('error', fn (string $event, array $params): bool => str_contains($params[0], '300.1.1.1'));

    expect($application->refresh()->ip_allowlist)->toBeNull();

    Livewire::test(General::class, ['application' => $application])
        ->set('ipAllowlist', '203.0.113.5 10.0.0.0/8')
        ->call('submit')
        ->assertNotDispatched('error')
        ->assertSet('ipAllowlist', '203.0.113.5,10.0.0.0/8');

    expect($application->refresh()->ip_allowlist)->toBe('203.0.113.5,10.0.0.0/8');
});

test('generated application labels include the allowlist for the app and its previews', function () {
    $application = ipAllowlistApplication(['ip_allowlist' => '203.0.113.5']);
    $preview = ApplicationPreview::create([
        'application_id' => $application->id,
        'pull_request_id' => 7,
        'pull_request_html_url' => 'https://github.com/coollabsio/coolify/pull/7',
        'fqdn' => 'https://pr-7.example.com',
    ]);

    expect(generateLabelsApplication($application))
        ->toContain("traefik.http.middlewares.ip-allowlist-{$application->uuid}.ipallowlist.sourcerange=203.0.113.5")
        ->toContain('caddy_0.@ip_not_allowed=not remote_ip 203.0.113.5')
        ->and(generateLabelsApplication($application, $preview))
        ->toContain("traefik.http.middlewares.ip-allowlist-{$application->uuid}-pr-7.ipallowlist.sourcerange=203.0.113.5");
});
