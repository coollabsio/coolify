<?php

use App\Enums\ProxyTypes;
use App\Livewire\Project\Application\General;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
    InstanceSettings::unguarded(function () {
        InstanceSettings::updateOrCreate(['id' => 0], []);
    });

    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->privateKey = PrivateKey::create([
        'name' => 'Test Key',
        'private_key' => '-----BEGIN OPENSSH PRIVATE KEY-----
b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW
QyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevAAAAJi/QySHv0Mk
hwAAAAtzc2gtZWQyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevA
AAAECBQw4jg1WRT2IGHMncCiZhURCts2s24HoDS0thHnnRKVuGmoeGq/pojrsyP1pszcNV
uZx9iFkCELtxrh31QJ68AAAAEXNhaWxANzZmZjY2ZDJlMmRkAQIDBA==
-----END OPENSSH PRIVATE KEY-----',
        'team_id' => $this->team->id,
    ]);
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
    ]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->first()
        ?? StandaloneDocker::factory()->create(['server_id' => $this->server->id, 'network' => 'coolify-test']);
});

afterEach(fn () => Server::flushIdentityMap());

function createApplicationForHttpBasicAuth(array $overrides = []): Application
{
    return Application::factory()->create(array_merge([
        'name' => 'Basic Auth App',
        'environment_id' => test()->environment->id,
        'destination_id' => test()->destination->id,
        'destination_type' => StandaloneDocker::class,
        'build_pack' => 'nixpacks',
        'static_image' => 'nginx:alpine',
        'base_directory' => '/',
        'is_http_basic_auth_enabled' => true,
        'http_basic_auth_username' => 'admin',
        'http_basic_auth_password' => 'secret',
        'redirect' => 'no',
        'git_repository' => 'coollabsio/coolify',
        'git_branch' => 'main',
        'ports_exposes' => '3000',
    ], $overrides));
}

test('http basic auth form shows a single username and password without multi-user controls', function () {
    $application = createApplicationForHttpBasicAuth();

    Livewire::test(General::class, ['application' => $application])
        ->assertSuccessful()
        ->assertSet('isHttpBasicAuthEnabled', true)
        ->assertSet('httpBasicAuthUsername', 'admin')
        ->assertSee('Username')
        ->assertSee('Password')
        ->assertDontSee('Add user')
        ->assertDontSee('Remove user')
        ->assertDontSee('The default user cannot be removed');
});

test('http basic auth credentials can be saved through the general form', function () {
    $application = createApplicationForHttpBasicAuth([
        'http_basic_auth_username' => 'old-user',
        'http_basic_auth_password' => 'old-pass',
    ]);

    Livewire::test(General::class, ['application' => $application])
        ->set('httpBasicAuthUsername', 'new-user')
        ->set('httpBasicAuthPassword', 'new-pass')
        ->call('submit')
        ->assertHasNoErrors();

    $application->refresh();

    expect($application->http_basic_auth_username)->toBe('new-user')
        ->and($application->http_basic_auth_password)->toBe('new-pass')
        ->and((bool) $application->is_http_basic_auth_enabled)->toBeTrue();
});

test('disabling http basic auth is saved instantly', function () {
    $application = createApplicationForHttpBasicAuth();

    Livewire::test(General::class, ['application' => $application])
        ->set('isHttpBasicAuthEnabled', false)
        ->call('instantSave')
        ->assertHasNoErrors()
        ->assertSet('isHttpBasicAuthEnabled', false);

    $application->refresh();

    expect((bool) $application->is_http_basic_auth_enabled)->toBeFalse();
});

function useCaddyProxyForHttpBasicAuth(string $image): void
{
    test()->server->forceFill(['proxy' => [
        'type' => ProxyTypes::CADDY->value,
        'status' => 'running',
        'last_saved_settings' => 'applied',
        'last_applied_settings' => 'applied',
        'last_saved_proxy_configuration' => "services:\n  caddy:\n    image: '{$image}'\n",
    ]])->save();
}

test('changing the bcrypt cost factor is saved instantly and applied to the proxy labels', function () {
    $application = createApplicationForHttpBasicAuth(['fqdn' => 'https://app.example.com']);

    Livewire::test(General::class, ['application' => $application])
        ->set('httpBasicAuthBcryptCost', 4)
        ->call('instantSave')
        ->assertDispatched('success');

    $application->refresh();

    expect($application->http_basic_auth_bcrypt_cost)->toBe(4)
        ->and(base64_decode($application->custom_labels))->toContain('.basicauth.users=admin:$2y$04$');
});

test('selecting Argon2id is saved instantly and applied to the Caddy labels', function () {
    useCaddyProxyForHttpBasicAuth('lucaslorentz/caddy-docker-proxy:2.13-alpine');
    $application = createApplicationForHttpBasicAuth(['fqdn' => 'https://app.example.com']);

    Livewire::test(General::class, ['application' => $application])
        ->set('httpBasicAuthHashAlgorithm', 'argon2id')
        ->set('httpBasicAuthArgon2idMemoryCost', 8192)
        ->call('instantSave')
        ->assertDispatched('success')
        ->assertSee('Iterations')
        ->assertDontSee('Cost factor');

    expect(base64_decode($application->refresh()->custom_labels))
        ->toContain('caddy_0.basic_auth=argon2id', 'caddy_0.basic_auth.admin="$argon2id$v=19$m=8192,t=4,p=1$');
});

test('http basic auth form explains why Argon2id cannot be selected', function (?string $image, string $reason) {
    if ($image !== null) {
        useCaddyProxyForHttpBasicAuth($image);
    }

    Livewire::test(General::class, ['application' => createApplicationForHttpBasicAuth()])
        ->assertSee($reason);
})->with([
    'Traefik' => [null, 'Only supported by the Caddy proxy'],
    'Caddy 2.10' => ['lucaslorentz/caddy-docker-proxy:2.10-alpine', 'Needs Caddy 2.11 or newer on this server'],
]);

test('an unsupported http basic auth setting is rejected', function (string $property, mixed $value) {
    Livewire::test(General::class, ['application' => createApplicationForHttpBasicAuth()])
        ->set($property, $value)
        ->call('instantSave')
        ->assertDispatched('error')
        ->assertNotDispatched('success');
})->with([
    'Argon2id on a proxy without it' => ['httpBasicAuthHashAlgorithm', 'argon2id'],
    'bcrypt cost above 14' => ['httpBasicAuthBcryptCost', 15],
    'password longer than 72 characters' => ['httpBasicAuthPassword', str_repeat('a', 73)],
]);
