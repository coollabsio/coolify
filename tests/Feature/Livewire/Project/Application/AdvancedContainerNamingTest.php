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

function createApplicationForContainerNamingTest(): Application
{
    $team = Team::factory()->create();
    $team->members()->attach(auth()->id(), ['role' => 'owner']);
    session(['currentTeam' => $team]);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    return Application::create([
        'name' => 'container-naming-test-app',
        'git_repository' => 'https://github.com/coollabsio/coolify',
        'git_branch' => 'main',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'environment_id' => $environment->id,
        'destination_id' => $server->standaloneDockers()->firstOrFail()->id,
        'destination_type' => $server->standaloneDockers()->firstOrFail()->getMorphClass(),
    ]);
}

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('saves consistent container naming when container labels are managed manually', function () {
    $application = createApplicationForContainerNamingTest();

    // Renders the proxy empty-state that builds a configuration route.
    $application->settings->update([
        'is_container_label_readonly_enabled' => false,
    ]);

    $application = $application->fresh(['environment.project', 'settings', 'destination']);

    Livewire::test(Advanced::class, ['application' => $application])
        ->set('isConsistentContainerNameEnabled', true)
        ->call('instantSave')
        ->assertSuccessful()
        ->assertHasNoErrors()
        ->assertDispatched('success')
        ->assertSee('Go to Container labels');

    expect($application->settings()->first()->is_consistent_container_name_enabled)->toBeTrue();
});

it('renders the configuration link from application uuids when labels are manual', function () {
    $application = createApplicationForContainerNamingTest();
    $application->settings->update([
        'is_container_label_readonly_enabled' => false,
    ]);

    $application = $application->fresh(['environment.project', 'settings', 'destination']);

    $expectedHref = route('project.application.configuration', [
        'project_uuid' => $application->environment->project->uuid,
        'environment_uuid' => $application->environment->uuid,
        'application_uuid' => $application->uuid,
    ]).'#container-labels-section';

    Livewire::test(Advanced::class, ['application' => $application])
        ->assertSuccessful()
        ->assertSee($expectedHref, false);
});

it('toggles consistent naming when labels are managed by coolify', function () {
    $application = createApplicationForContainerNamingTest();
    $application->settings->update([
        'is_container_label_readonly_enabled' => true,
    ]);

    $application = $application->fresh(['environment.project', 'settings', 'destination']);

    Livewire::test(Advanced::class, ['application' => $application])
        ->set('isConsistentContainerNameEnabled', true)
        ->call('instantSave')
        ->assertSuccessful()
        ->assertHasNoErrors()
        ->assertDispatched('success');

    expect($application->settings()->first()->is_consistent_container_name_enabled)->toBeTrue();
});

it('only shows the custom container name for consistent naming', function () {
    $application = createApplicationForContainerNamingTest();
    $application = $application->fresh(['environment.project', 'settings', 'destination']);

    Livewire::test(Advanced::class, ['application' => $application])
        ->assertDontSee('Custom container name')
        ->set('isConsistentContainerNameEnabled', true)
        ->assertSee('Custom container name');
});
