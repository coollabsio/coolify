<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\ScheduledDatabaseBackup;
use App\Models\Server;
use App\Models\ServiceDatabase;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = $this->server->standaloneDockers()->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);

    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => <<<'YAML'
services:
  web:
    image: ghcr.io/acme/example-web:latest
    environment:
      APP_ENV: production
  postgres:
    image: postgres:16
    environment:
      POSTGRES_USER: postgres
      POSTGRES_PASSWORD: secret
      POSTGRES_DB: app
YAML,
        'compose_parsing_version' => '5',
    ]);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

test('application docker compose parsing persists service databases for compose-backed apps', function () {
    $parsed = $this->application->parse();

    expect($parsed)->not->toBeNull();

    $database = ServiceDatabase::query()
        ->where('application_id', $this->application->id)
        ->where('name', 'postgres')
        ->first();

    expect($database)->not->toBeNull()
        ->and($database->service_id)->toBeNull()
        ->and($database->image)->toBe('postgres:16');
});

test('scheduled backup resolves server for application-backed compose databases', function () {
    $database = ServiceDatabase::create([
        'name' => 'postgres',
        'image' => 'postgres:16',
        'application_id' => $this->application->id,
        'service_id' => null,
    ]);

    $backup = ScheduledDatabaseBackup::create([
        'team_id' => $this->team->id,
        'frequency' => '0 2 * * *',
        'database_type' => ServiceDatabase::class,
        'database_id' => $database->id,
    ]);

    expect($backup->server())->not->toBeNull()
        ->and($backup->server()->id)->toBe($this->server->id);
});
