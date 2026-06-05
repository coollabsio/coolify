<?php

use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

function createServiceWithImage(string $image): Service
{
    $team = currentTeam();

    $server = Server::factory()->create([
        'team_id' => $team->id,
    ]);

    $destination = $server->standaloneDockers()->first();

    $project = Project::factory()->create([
        'team_id' => $team->id,
    ]);

    $environment = Environment::factory()->create([
        'project_id' => $project->id,
    ]);

    $service = Service::factory()->create([
        'destination_type' => StandaloneDocker::class,
        'destination_id' => $destination->id,
        'environment_id' => $environment->id,
    ]);

    $application = new ServiceApplication;
    $application->forceFill([
        'service_id' => $service->id,
        'name' => str($image)->before(':')->afterLast('/')->value(),
        'image' => $image,
    ]);
    $application->save();

    return $service;
}

it('returns Grafana extra fields for grafana/grafana', function () {
    $service = createServiceWithImage('grafana/grafana:latest');
    $fields = $service->extraFields();

    expect($fields->has('Grafana'))->toBeTrue();
    expect(data_get($fields->get('Grafana'), 'Admin User.key'))->toBe('GF_SECURITY_ADMIN_USER');
});

it('returns Grafana extra fields for grafana/grafana-oss', function () {
    $service = createServiceWithImage('grafana/grafana-oss:latest');
    $fields = $service->extraFields();

    expect($fields->has('Grafana'))->toBeTrue();
    expect(data_get($fields->get('Grafana'), 'Admin User.key'))->toBe('GF_SECURITY_ADMIN_USER');
});

it('returns Grafana extra fields for grafana/grafana-enterprise', function () {
    $service = createServiceWithImage('grafana/grafana-enterprise:latest');
    $fields = $service->extraFields();

    expect($fields->has('Grafana'))->toBeTrue();
    expect(data_get($fields->get('Grafana'), 'Admin User.key'))->toBe('GF_SECURITY_ADMIN_USER');
});

it('does not return Grafana extra fields for grafana/loki', function () {
    $service = createServiceWithImage('grafana/loki:latest');
    $fields = $service->extraFields();

    expect($fields->has('Grafana'))->toBeFalse();
});

it('does not return Grafana extra fields for other Grafana-published images', function (string $image) {
    $service = createServiceWithImage($image);
    $fields = $service->extraFields();

    expect($fields->has('Grafana'))->toBeFalse();
})->with([
    'grafana/promtail' => 'grafana/promtail:latest',
    'grafana/tempo' => 'grafana/tempo:latest',
    'grafana/mimir' => 'grafana/mimir:latest',
    'grafana/agent' => 'grafana/agent:latest',
    'grafana/alloy' => 'grafana/alloy:latest',
]);
