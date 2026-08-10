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

/**
 * Regression for #11079: removing all domains must save without TypeError from
 * sslipDomainWarning(null) after normalizeApplicationDomains returns null.
 */
test('can clear application domains and save successfully', function () {
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => StandaloneDocker::class,
        'build_pack' => 'nixpacks',
        'fqdn' => 'https://example.com',
        'static_image' => 'nginx:alpine',
        'base_directory' => '/',
        'ports_exposes' => '3000',
        'is_http_basic_auth_enabled' => false,
        'redirect' => 'no',
    ]);

    Livewire::test(General::class, ['application' => $application])
        ->assertSuccessful()
        ->set('fqdn', null)
        ->call('submit')
        ->assertHasNoErrors()
        ->assertNotDispatched('error')
        ->assertDispatched('success');

    $application->refresh();
    expect($application->fqdn)->toBeNull();
});

test('can clear application domains via empty string and save successfully', function () {
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => StandaloneDocker::class,
        'build_pack' => 'nixpacks',
        'fqdn' => 'https://app.example.com,https://www.example.com',
        'static_image' => 'nginx:alpine',
        'base_directory' => '/',
        'ports_exposes' => '3000',
        'is_http_basic_auth_enabled' => false,
        'redirect' => 'no',
    ]);

    Livewire::test(General::class, ['application' => $application])
        ->assertSuccessful()
        ->set('fqdn', '')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertNotDispatched('error')
        ->assertDispatched('success');

    $application->refresh();
    expect($application->fqdn)->toBeNull();
});
