<?php

use App\Livewire\Project\Application\Advanced;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function createApplicationForDockerBuildCacheSettingsTest(string $buildPack = 'dockerfile'): Application
{
    $team = Team::factory()->create();
    $team->members()->attach(auth()->id(), ['role' => 'owner']);
    session(['currentTeam' => $team]);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    return Application::factory()->create([
        'build_pack' => $buildPack,
        'environment_id' => $environment->id,
        'destination_id' => $server->standaloneDockers()->firstOrFail()->id,
        'destination_type' => $server->standaloneDockers()->firstOrFail()->getMorphClass(),
    ]);
}

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('Dockerfile applications can save registry build cache settings', function () {
    $application = createApplicationForDockerBuildCacheSettingsTest();

    Livewire::test(Advanced::class, ['application' => $application])
        ->assertSee('External Build Cache')
        ->assertSeeHtml('wire:model.live=dockerBuildCacheEnabled')
        ->set('dockerBuildCacheEnabled', true)
        ->set('dockerBuildCacheMode', 'registry')
        ->set('dockerBuildCacheFrom', 'registry.example.com/team/app:buildcache')
        ->set('dockerBuildCacheTo', 'registry.example.com/team/app:buildcache')
        ->set('dockerBuildCacheFailurePolicy', 'continue')
        ->set('previewDockerBuildCacheMode', 'inherit')
        ->call('saveDockerBuildCache')
        ->assertHasNoErrors()
        ->assertDispatched('success')
        ->assertDispatched('configurationChanged');

    expect($application->settings()->first()->docker_build_cache)->toBe([
        'enabled' => true,
        'cache_from' => ['type' => 'registry', 'value' => 'registry.example.com/team/app:buildcache'],
        'cache_to' => ['type' => 'registry', 'value' => 'registry.example.com/team/app:buildcache'],
        'failure_policy' => 'continue',
    ])->and($application->settings()->first()->preview_docker_build_cache)->toBeNull();
});

test('preview build cache can explicitly disable production inheritance', function () {
    $application = createApplicationForDockerBuildCacheSettingsTest();

    Livewire::test(Advanced::class, ['application' => $application])
        ->set('dockerBuildCacheEnabled', false)
        ->set('previewDockerBuildCacheMode', 'disabled')
        ->call('saveDockerBuildCache')
        ->assertHasNoErrors();

    expect($application->settings()->first()->preview_docker_build_cache)->toBe(['enabled' => false]);
});

test('advanced raw local cache settings can be saved', function () {
    $application = createApplicationForDockerBuildCacheSettingsTest();

    Livewire::test(Advanced::class, ['application' => $application])
        ->set('dockerBuildCacheEnabled', true)
        ->set('dockerBuildCacheMode', 'raw')
        ->set('dockerBuildCacheFrom', 'type=local,src=/cache')
        ->set('dockerBuildCacheTo', 'type=local,dest=/cache,mode=max')
        ->set('dockerBuildCacheFailurePolicy', 'fail')
        ->call('saveDockerBuildCache')
        ->assertHasNoErrors();

    expect($application->settings()->first()->docker_build_cache)->toMatchArray([
        'cache_from' => ['type' => 'raw', 'value' => 'type=local,src=/cache'],
        'cache_to' => ['type' => 'raw', 'value' => 'type=local,dest=/cache,mode=max'],
        'failure_policy' => 'fail',
    ]);
});

test('registry mode requires explicit cache references', function () {
    $application = createApplicationForDockerBuildCacheSettingsTest();

    Livewire::test(Advanced::class, ['application' => $application])
        ->set('dockerBuildCacheEnabled', true)
        ->set('dockerBuildCacheMode', 'registry')
        ->set('dockerBuildCacheFrom', '')
        ->set('dockerBuildCacheTo', '')
        ->call('saveDockerBuildCache')
        ->assertHasErrors(['dockerBuildCacheFrom', 'dockerBuildCacheTo']);

    expect($application->settings()->first()->docker_build_cache)->toBeNull();
});

test('external build cache settings are hidden for other build packs', function () {
    $application = createApplicationForDockerBuildCacheSettingsTest('nixpacks');

    Livewire::test(Advanced::class, ['application' => $application])
        ->assertDontSee('External Build Cache');
});
