<?php

use App\Models\Application;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::forceCreate(['id' => 0]);
    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $this->responseTextEnvironment = $project->environments()->firstOrFail();
    $this->responseTextDestination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
});

test('clears the expected healthcheck response texts that the healthcheck did not check', function () {
    $application = fn (?string $text) => Application::factory()->create([
        'environment_id' => $this->responseTextEnvironment->id,
        'destination_id' => $this->responseTextDestination->id,
        'destination_type' => StandaloneDocker::class,
        'health_check_response_text' => $text,
        'health_check_path' => '/health',
        'health_check_return_code' => 401,
    ]);
    $withText = $application('OK');
    $withoutText = $application(null);

    (require database_path('migrations/2026_11_16_085347_clear_unchecked_health_check_response_texts.php'))->up();

    expect($withText->fresh()->health_check_response_text)->toBeNull()
        ->and($withText->fresh()->health_check_path)->toBe('/health')
        ->and($withText->fresh()->health_check_return_code)->toBe(401)
        ->and($withoutText->fresh()->health_check_response_text)->toBeNull();
});
