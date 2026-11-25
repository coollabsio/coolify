<?php

use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('routes the appwrite api and realtime to port 80 and the console to port 3000', function () {
    InstanceSettings::forceCreate(['id' => 0]);
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->first()
        ?? StandaloneDocker::factory()->create(['server_id' => $server->id]);
    $environment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $team->id])->id]);
    $service = Service::factory()->create([
        'service_type' => 'appwrite',
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'docker_compose_raw' => file_get_contents(base_path('templates/compose/appwrite.yaml')),
    ]);

    $requiredPort = fn (string $name) => ServiceApplication::create(['name' => $name, 'service_id' => $service->id])->getRequiredPort();

    expect($requiredPort('appwrite'))->toBe(80)
        ->and($requiredPort('appwrite-realtime'))->toBe(80)
        ->and($requiredPort('appwrite-console'))->toBe(3000);
});
