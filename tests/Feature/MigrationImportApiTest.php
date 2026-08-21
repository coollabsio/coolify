<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);
    config(['app.maintenance.driver' => 'file', 'app.maintenance.store' => 'array']);
    $this->withoutMiddleware(PreventRequestsDuringMaintenance::class);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $this->token = $this->user->createToken('test-token', ['*']);
    $this->bearerToken = $this->token->plainTextToken;

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
        'is_build_server' => false,
    ]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = $this->project->environments()->first()
        ?? Environment::factory()->create(['project_id' => $this->project->id]);
});

test('imports a skip-data manifest and remaps linked database uuids', function () {
    $sourceDatabase = StandalonePostgresql::create([
        'name' => 'source-db',
        'postgres_user' => 'postgres',
        'postgres_password' => 'secret',
        'postgres_db' => 'app',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    $sourceApplication = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'name' => 'source-app',
    ]);

    EnvironmentVariable::create([
        'key' => 'DATABASE_URL',
        'value' => "postgres://postgres:secret@{$sourceDatabase->uuid}:5432/app",
        'resourceable_id' => $sourceApplication->id,
        'resourceable_type' => $sourceApplication->getMorphClass(),
        'is_preview' => false,
    ]);

    $export = $this->withHeaders([
        'Authorization' => 'Bearer '.$this->bearerToken,
        'Content-Type' => 'application/json',
    ])->postJson('/api/v1/migrations/export', [
        'resource_uuids' => [$sourceDatabase->uuid, $sourceApplication->uuid],
        'skip_data' => true,
        'storage' => [
            'driver' => 'local-ssh',
            'config' => [],
        ],
    ]);

    $export->assertCreated();
    $manifest = $export->json('manifest');
    expect($manifest)->toBeArray();

    $targetProject = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'Imported']);
    $targetEnvironment = $targetProject->environments()->first();

    $import = $this->withHeaders([
        'Authorization' => 'Bearer '.$this->bearerToken,
        'Content-Type' => 'application/json',
    ])->postJson('/api/v1/migrations/import', [
        'manifest' => $manifest,
        'destination_uuid' => $this->destination->uuid,
        'project_uuid' => $targetProject->uuid,
        'environment_uuid' => $targetEnvironment->uuid,
        'skip_data' => true,
        'storage' => ['driver' => 'local-ssh', 'config' => []],
    ]);

    $import->assertCreated()
        ->assertJsonPath('status', 'completed');

    $items = collect($import->json('items'));
    expect($items->pluck('status')->unique()->values()->all())->toBe(['healthy'])
        ->and($items->pluck('name')->all())->toContain('source-db')
        ->and($items->pluck('name')->all())->toContain('source-app');

    $importedDatabase = StandalonePostgresql::where('environment_id', $targetEnvironment->id)
        ->where('name', 'source-db')
        ->first();
    $importedApplication = Application::where('environment_id', $targetEnvironment->id)
        ->where('name', 'source-app')
        ->first();

    expect($importedDatabase)->not->toBeNull()
        ->and($importedApplication)->not->toBeNull()
        ->and($importedDatabase->uuid)->not->toBe($sourceDatabase->uuid);

    $url = $importedApplication->environment_variables()->where('key', 'DATABASE_URL')->first()?->value;
    expect($url)->toContain($importedDatabase->uuid)
        ->and($url)->not->toContain($sourceDatabase->uuid);
});
