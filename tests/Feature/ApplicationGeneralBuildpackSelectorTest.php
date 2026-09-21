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

class GeneralWithoutBuildpackSubmitSideEffects extends General
{
    public function render(): mixed
    {
        return view('livewire.project.application.general');
    }

    public function submit($showToaster = true): void
    {
        $this->application->save();
    }
}

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

test('existing application buildpack selector lists railpack before nixpacks', function () {
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => StandaloneDocker::class,
        'build_pack' => 'nixpacks',
        'static_image' => 'nginx:alpine',
        'base_directory' => '/',
        'is_http_basic_auth_enabled' => false,
        'redirect' => 'no',
    ]);

    Livewire::test(General::class, ['application' => $application])
        ->assertSuccessful()
        ->assertSeeInOrder([
            '<option value="railpack">Railpack</option>',
            '<option value="nixpacks">Nixpacks</option>',
        ], false);
});

test('existing application shows railpack without beta label in build pack selector', function () {
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => StandaloneDocker::class,
        'build_pack' => 'railpack',
        'static_image' => 'nginx:alpine',
        'base_directory' => '/',
        'is_http_basic_auth_enabled' => false,
        'redirect' => 'no',
    ]);

    Livewire::test(General::class, ['application' => $application])
        ->assertSuccessful()
        ->assertSee('Railpack')
        ->assertDontSee('Railpack (Beta)')
        ->assertDontSee('Railpack (beta)');
});

test('switching from railpack to compose preserves the existing application domains', function () {
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => StandaloneDocker::class,
        'build_pack' => 'railpack',
        'static_image' => 'nginx:alpine',
        'base_directory' => '/',
        'fqdn' => 'https://example.com,https://www.example.com',
        'is_http_basic_auth_enabled' => false,
        'redirect' => 'no',
    ]);

    Livewire::test(GeneralWithoutBuildpackSubmitSideEffects::class, ['application' => $application])
        ->assertSuccessful()
        ->set('buildPack', 'dockercompose')
        ->assertSet('dockerComposeLocation', '/docker-compose.yaml');

    expect($application->refresh()->fqdn)
        ->toBe('https://example.com,https://www.example.com');
});

test('networking section hints that internal ports can be set per domain', function () {
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => StandaloneDocker::class,
        'build_pack' => 'nixpacks',
        'static_image' => 'nginx:alpine',
        'base_directory' => '/',
        'ports_exposes' => '3000,3001',
        'is_http_basic_auth_enabled' => false,
        'redirect' => 'no',
    ]);

    $domainsUrl = route('project.application.domains', [
        'project_uuid' => $application->environment->project->uuid,
        'environment_uuid' => $application->environment->uuid,
        'application_uuid' => $application->uuid,
    ]);

    Livewire::test(General::class, ['application' => $application])
        ->assertSuccessful()
        ->assertSeeInOrder([
            'Ports exposes',
            'You can also set an internal port per domain on',
            'Port mappings',
        ])
        ->assertSee($domainsUrl, false);
});
