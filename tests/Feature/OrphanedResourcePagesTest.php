<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Queue::fake();
    config(['app.maintenance.store' => 'array']);
    InstanceSettings::forceCreate(['id' => 0]);

    $team = Team::factory()->create(['show_boarding' => false]);
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);
    $this->actingAs($user);
    session(['currentTeam' => $team]);
    Cache::flush();

    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $onDestination = [
        'environment_id' => $this->environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ];
    $this->service = Service::factory()->create([...$onDestination, 'name' => 'orphaned-service', 'server_id' => $server->id]);
    $this->database = StandalonePostgresql::create([...$onDestination,
        'name' => 'orphaned-database', 'postgres_user' => 'postgres', 'postgres_password' => 'password',
        'postgres_db' => 'db', 'image' => 'postgres:17',
    ]);
    $this->application = Application::factory()->create([...$onDestination, 'name' => 'orphaned-application']);

    // Deleting a server removes its destinations. Resources that were not deleted with it stay without a server.
    $server->forceDelete();
    Server::flushIdentityMap();
});

afterEach(function () {
    Server::flushIdentityMap();
});

function orphanedResourceRoute(object $test, string $name, array $parameters = []): string
{
    return route($name, [
        'project_uuid' => $test->project->uuid,
        'environment_uuid' => $test->environment->uuid,
        ...$parameters,
    ]);
}

it('lists resources whose server was deleted on the environment page', function () {
    $this->get(orphanedResourceRoute($this, 'project.resource.index'))
        ->assertOk()
        ->assertSee('orphaned-service')
        ->assertSee('orphaned-database')
        ->assertSee('orphaned-application');
});

it('opens the danger zone of resources whose server was deleted', function (string $route, string $parameter, string $resource) {
    $this->get(orphanedResourceRoute($this, $route, [$parameter => $this->{$resource}->uuid]))
        ->assertOk()
        ->assertSee('Danger Zone');
})->with([
    'service' => ['project.service.danger', 'service_uuid', 'service'],
    'database' => ['project.database.danger', 'database_uuid', 'database'],
    'application' => ['project.application.danger', 'application_uuid', 'application'],
]);

it('reports a resource without a server as not functional', function () {
    expect($this->service->fresh()->server_status)->toBeFalse()
        ->and($this->database->fresh()->server_status)->toBeFalse();
});
