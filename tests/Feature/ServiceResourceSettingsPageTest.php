<?php

use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    config()->set('app.maintenance.store', 'array');
    InstanceSettings::forceCreate(['id' => 0]);

    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => 'owner']);
    $this->actingAs($user);
    session(['currentTeam' => $team]);

    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $this->service = Service::factory()->create([
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $this->application = ServiceApplication::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'web',
        'service_id' => $this->service->id,
        'image' => 'example/web:latest',
        'status' => 'exited',
    ]);
    $this->database = ServiceDatabase::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'postgres',
        'service_id' => $this->service->id,
        'image' => 'postgres:17',
        'status' => 'exited',
    ]);
    $this->parameters = [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
        'service_uuid' => $this->service->uuid,
    ];
});

it('redirects old resource settings links to the service configuration page', function (string $routeName, string $resourceUuid) {
    $this->get(route($routeName, [
        ...$this->parameters,
        'stack_service_uuid' => $resourceUuid,
    ]))->assertRedirect(route('project.service.configuration', $this->parameters));
})->with([
    'application general' => fn () => ['project.service.index', $this->application->uuid],
    'application advanced' => fn () => ['project.service.index.advanced', $this->application->uuid],
    'database general' => fn () => ['project.service.index', $this->database->uuid],
    'database advanced' => fn () => ['project.service.index.advanced', $this->database->uuid],
]);
