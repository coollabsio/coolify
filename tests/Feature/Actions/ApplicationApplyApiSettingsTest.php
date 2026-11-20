<?php

use App\Models\Application;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function appForSettings(): Application
{
    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = $server->destinations()->first();

    return Application::factory()->create([
        'environment_id' => $project->environments()->first()->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
}

it('exposes the API settings field list', function () {
    expect(Application::API_SETTING_FIELDS)->toContain('is_gzip_enabled')->toContain('custom_internal_name')
        ->and(Application::BOOLEAN_API_SETTING_FIELDS)->toContain('is_gzip_enabled')->not->toContain('custom_internal_name');
});

it('applies settings to the ApplicationSetting row and is a no-op on empty input', function () {
    $app = appForSettings();

    $app->applyApiSettings([]);
    $app->applyApiSettings(['is_gzip_enabled' => false, 'stop_grace_period' => 42]);

    expect($app->settings->fresh()->is_gzip_enabled)->toBeFalse()
        ->and($app->settings->fresh()->stop_grace_period)->toBe(42);
});
