<?php

use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Regression test: EnvironmentVariable hides `value` in serialized output, so the
 * config hash must makeVisible('value') before json_encode — otherwise editing an
 * env var value no longer flips the hash and no restart is suggested.
 */
it('detects environment variable value change in database config hash', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $destination = $server->standaloneDockers()->firstOrFail();

    $database = StandalonePostgresql::create([
        'name' => 'config-hash-db',
        'postgres_user' => 'postgres',
        'postgres_password' => encrypt('password'),
        'postgres_db' => 'app',
        'image' => 'postgres:16-alpine',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    $variable = $database->environment_variables()->create([
        'key' => 'APP_SECRET',
        'value' => 'original-value',
    ]);

    $database->isConfigurationChanged(save: true);
    expect($database->refresh()->isConfigurationChanged())->toBeFalse();

    $variable->update(['value' => 'changed-value']);

    expect($database->refresh()->isConfigurationChanged())->toBeTrue();
});
