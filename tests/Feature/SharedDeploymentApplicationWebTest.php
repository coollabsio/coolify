<?php

use App\Livewire\Project\New\SimpleDockerfile;
use App\Models\Application;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Visus\Cuid2\Cuid2;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.maintenance.driver' => 'file']);

    InstanceSettings::unguarded(
        fn () => InstanceSettings::firstOrCreate(['id' => 0])
    );

    $this->ownerTeam = Team::factory()->create();
    $this->deploymentTeam = Team::factory()->create();

    $this->deploymentUser = User::factory()->create();
    $this->deploymentTeam->members()->attach(
        $this->deploymentUser->id,
        ['role' => 'owner']
    );
    $this->deploymentUser->load('teams');

    $this->server = Server::factory()->create([
        'team_id' => $this->ownerTeam->id,
    ]);

    $this->server->settings()->update([
        'is_build_server' => false,
        'is_reachable' => true,
        'is_usable' => true,
        'is_swarm_worker' => false,
        'is_swarm_manager' => false,
        'force_disabled' => false,
    ]);

    $this->destination = $this->server
        ->standaloneDockers()
        ->firstOrFail();

    $this->project = Project::create([
        'uuid' => (string) new Cuid2,
        'name' => 'shared-deployment-web-project',
        'team_id' => $this->deploymentTeam->id,
    ]);

    $this->environment = $this->project
        ->environments()
        ->firstOrFail();

    $this->routeParameters = [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ];

    $this->actingAs($this->deploymentUser);
    session(['currentTeam' => $this->deploymentTeam]);
});

function sharedDeploymentDockerfileComponent($test)
{
    return Livewire::withUrlParams([
        'destination' => $test->destination->uuid,
    ])
        ->test(
            SimpleDockerfile::class,
            $test->routeParameters
        )
        ->set('parameters', $test->routeParameters)
        ->set(
            'dockerfile',
            "FROM nginx:alpine\nEXPOSE 80\n"
        );
}

test('team can create a web application on an authorized shared server', function () {
    $this->server->sharedTeams()->attach(
        $this->deploymentTeam->id,
        [
            'can_build' => false,
            'can_deploy' => true,
        ]
    );

    sharedDeploymentDockerfileComponent($this)
        ->call('submit')
        ->assertHasNoErrors();

    $application = Application::query()
        ->with('environment.project', 'destination.server')
        ->sole();

    expect($application->environment->project->team_id)
        ->toBe($this->deploymentTeam->id)
        ->and($application->destination_id)
        ->toBe($this->destination->id)
        ->and($application->destination->server_id)
        ->toBe($this->server->id)
        ->and($application->destination->server->team_id)
        ->toBe($this->ownerTeam->id)
        ->and(
            Application::ownedByCurrentTeamAPI(
                $this->deploymentTeam->id
            )->whereKey($application->id)->exists()
        )
        ->toBeTrue()
        ->and(
            Application::ownedByCurrentTeamAPI(
                $this->ownerTeam->id
            )->whereKey($application->id)->exists()
        )
        ->toBeFalse();
});

test('crafted web submission cannot use an unauthorized shared server', function () {
    expect(
        fn () => sharedDeploymentDockerfileComponent($this)
            ->call('submit')
    )->toThrow(Exception::class, 'Destination not found.');

    expect(Application::query()->count())->toBe(0);
});

test('revoked deployment access is revalidated when the form is submitted', function () {
    $this->server->sharedTeams()->attach(
        $this->deploymentTeam->id,
        [
            'can_build' => false,
            'can_deploy' => true,
        ]
    );

    $component = sharedDeploymentDockerfileComponent($this);

    expect(
        find_deployable_resource_destination_for_current_team(
            $this->destination->uuid
        )
    )->not->toBeNull();

    $this->server->sharedTeams()->updateExistingPivot(
        $this->deploymentTeam->id,
        ['can_deploy' => false]
    );

    expect(
        fn () => $component->call('submit')
    )->toThrow(Exception::class, 'Destination not found.');

    expect(Application::query()->count())->toBe(0);
});
