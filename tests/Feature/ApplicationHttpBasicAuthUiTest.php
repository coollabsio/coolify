<?php

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

function createApplicationForHttpBasicAuth(array $overrides = []): Application
{
    return Application::factory()->create(array_merge([
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
