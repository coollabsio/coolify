<?php

use App\Livewire\Project\Application\General;
use App\Livewire\Server\LogDrains;
use App\Livewire\Server\Sentinel;
use App\Livewire\Server\Show;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));
    $this->team = Team::factory()->create(['show_boarding' => false]);
    $this->member = User::factory()->create();
    $this->admin = User::factory()->create();
    $this->team->members()->attach($this->member, ['role' => 'member']);
    $this->team->members()->attach($this->admin, ['role' => 'admin']);
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->server->settings->updateQuietly([
        'sentinel_token' => 'SENTINEL-MARKER-SECRET',
        'sentinel_custom_url' => 'https://sentinel.example.test/MARKER-SECRET',
        'logdrain_newrelic_license_key' => 'NEWRELIC-MARKER-SECRET',
        'logdrain_axiom_api_key' => 'AXIOM-MARKER-SECRET',
        'logdrain_custom_config' => 'CUSTOM-CONFIG-MARKER-SECRET',
        'logdrain_custom_config_parser' => 'CUSTOM-PARSER-MARKER-SECRET',
    ]);
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'http_basic_auth_password' => 'BASIC-MARKER-SECRET',
        'is_http_basic_auth_enabled' => true,
        'http_basic_auth_username' => 'admin',
        'redirect' => 'no',
        'static_image' => 'nginx:alpine',
        'base_directory' => '/',
        'build_pack' => 'nixpacks',
    ]);
});

function useSecretExposureUser(User $user, Team $team): void
{
    test()->actingAs($user);
    session(['currentTeam' => $team]);
}

test('server overview redacts the Sentinel token from a member snapshot', function () {
    useSecretExposureUser($this->member, $this->team);
    Livewire::test(Show::class, ['server_uuid' => $this->server->uuid])
        ->assertSuccessful()->assertSet('sentinelToken', '')->assertSet('sentinelCustomUrl', null)
        ->call('refresh')->assertSet('sentinelToken', '')->assertSet('sentinelCustomUrl', null);
    $this->get(route('server.show', ['server_uuid' => $this->server->uuid]))
        ->assertSuccessful()->assertDontSee('SENTINEL-MARKER-SECRET')->assertDontSee('sentinel.example.test/MARKER-SECRET');

    useSecretExposureUser($this->admin, $this->team);
    Livewire::test(Show::class, ['server_uuid' => $this->server->uuid])
        ->assertSuccessful()->assertSet('sentinelToken', 'SENTINEL-MARKER-SECRET')
        ->assertSet('sentinelCustomUrl', 'https://sentinel.example.test/MARKER-SECRET');
});

test('log drain page redacts provider keys from a member snapshot', function () {
    useSecretExposureUser($this->member, $this->team);
    Livewire::test(LogDrains::class, ['server_uuid' => $this->server->uuid])
        ->assertSuccessful()->assertSet('logDrainNewRelicLicenseKey', null)->assertSet('logDrainAxiomApiKey', null)
        ->assertSet('logDrainCustomConfig', null)->assertSet('logDrainCustomConfigParser', null);
    $this->get(route('server.log-drains', ['server_uuid' => $this->server->uuid]))
        ->assertSuccessful()->assertDontSee('NEWRELIC-MARKER-SECRET')->assertDontSee('AXIOM-MARKER-SECRET')
        ->assertDontSee('CUSTOM-CONFIG-MARKER-SECRET')->assertDontSee('CUSTOM-PARSER-MARKER-SECRET');

    useSecretExposureUser($this->admin, $this->team);
    Livewire::test(LogDrains::class, ['server_uuid' => $this->server->uuid])
        ->assertSuccessful()->assertSet('logDrainNewRelicLicenseKey', 'NEWRELIC-MARKER-SECRET')
        ->assertSet('logDrainAxiomApiKey', 'AXIOM-MARKER-SECRET')
        ->assertSet('logDrainCustomConfig', 'CUSTOM-CONFIG-MARKER-SECRET')
        ->assertSet('logDrainCustomConfigParser', 'CUSTOM-PARSER-MARKER-SECRET');
});

test('application page redacts HTTP basic password from a member snapshot', function () {
    useSecretExposureUser($this->member, $this->team);
    Livewire::test(General::class, ['application' => $this->application])
        ->assertSuccessful()->assertSet('httpBasicAuthPassword', null);
    $this->get(route('project.application.configuration', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'application_uuid' => $this->application->uuid,
    ]))->assertSuccessful()->assertDontSee('BASIC-MARKER-SECRET');

    useSecretExposureUser($this->admin, $this->team);
    Livewire::test(General::class, ['application' => $this->application])
        ->assertSuccessful()->assertSet('httpBasicAuthPassword', 'BASIC-MARKER-SECRET');
});

test('the dedicated Sentinel page denies members and cross-team users', function () {
    useSecretExposureUser($this->member, $this->team);
    $this->get(route('server.sentinel', ['server_uuid' => $this->server->uuid]))->assertForbidden();
    Livewire::test(Sentinel::class, ['server' => $this->server])->assertForbidden();

    useSecretExposureUser($this->admin, $this->team);
    $this->get(route('server.sentinel', ['server_uuid' => $this->server->uuid]))->assertSuccessful();
    Livewire::test(Sentinel::class, ['server' => $this->server])
        ->assertSuccessful()->assertSet('sentinelToken', 'SENTINEL-MARKER-SECRET');

    $outsider = User::factory()->create();
    $outsideTeam = $outsider->teams()->first();
    $outsideTeam->updateQuietly(['show_boarding' => false]);
    useSecretExposureUser($outsider, $outsideTeam);
    $this->get(route('server.show', ['server_uuid' => $this->server->uuid]))->assertNotFound();
    $this->get(route('server.log-drains', ['server_uuid' => $this->server->uuid]))->assertNotFound();
    $this->get(route('server.sentinel', ['server_uuid' => $this->server->uuid]))->assertNotFound();
    $this->get(route('project.application.configuration', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'application_uuid' => $this->application->uuid,
    ]))->assertNotFound();
});

test('model serialization keeps the three secrets hidden', function () {
    expect(json_encode($this->server->toArray()))
        ->not->toContain('SENTINEL-MARKER-SECRET', 'NEWRELIC-MARKER-SECRET', 'AXIOM-MARKER-SECRET', 'CUSTOM-CONFIG-MARKER-SECRET', 'CUSTOM-PARSER-MARKER-SECRET', 'sentinel.example.test/MARKER-SECRET');
    expect(json_encode($this->server->settings->toArray()))
        ->not->toContain('SENTINEL-MARKER-SECRET', 'NEWRELIC-MARKER-SECRET', 'AXIOM-MARKER-SECRET', 'CUSTOM-CONFIG-MARKER-SECRET', 'CUSTOM-PARSER-MARKER-SECRET', 'sentinel.example.test/MARKER-SECRET');
    expect(json_encode($this->application->toArray()))->not->toContain('BASIC-MARKER-SECRET');
});
