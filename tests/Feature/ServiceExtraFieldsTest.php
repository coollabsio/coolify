<?php

use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function serviceExtraFieldsTestServiceWithApplicationImage(string $image): Service
{
    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();

    $service = Service::factory()->create([
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);

    $service->applications()->create([
        'name' => 'app',
        'image' => $image,
    ]);

    return $service;
}

it('only adds Grafana extra fields for Grafana server images', function (string $image, bool $shouldHaveGrafanaFields) {
    $fields = serviceExtraFieldsTestServiceWithApplicationImage($image)->extraFields();

    expect($fields->has('Grafana'))->toBe($shouldHaveGrafanaFields);
})->with([
    'grafana oss' => ['grafana/grafana-oss:latest', true],
    'grafana enterprise' => ['grafana/grafana-enterprise:latest', true],
    'grafana default' => ['grafana/grafana:latest', true],
    'loki' => ['grafana/loki:latest', false],
    'promtail' => ['grafana/promtail:latest', false],
    'tempo' => ['grafana/tempo:latest', false],
]);
