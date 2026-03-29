<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::updateOrCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    session(['currentTeam' => $this->team]);
    $this->actingAs($this->user);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->first();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
});

test('database references only shows databases on the same server', function () {
    $otherServer = Server::factory()->create(['team_id' => $this->team->id]);
    $otherDestination = StandaloneDocker::where('server_id', $otherServer->id)->first();

    $sameServerDb = StandalonePostgresql::create([
        'name' => 'same-server-db',
        'image' => 'postgres:15-alpine',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'testdb',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    $otherServerDb = StandalonePostgresql::create([
        'name' => 'other-server-db',
        'image' => 'postgres:15-alpine',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'otherdb',
        'environment_id' => $this->environment->id,
        'destination_id' => $otherDestination->id,
        'destination_type' => $otherDestination->getMorphClass(),
    ]);

    $serverId = $this->server->id;
    $eagerLoad = ['destination.server'];
    $databases = collect()
        ->merge($this->environment->postgresqls()->with($eagerLoad)->get())
        ->filter(fn ($db) => $db->destination->server->id === $serverId);

    expect($databases)->toHaveCount(1);
    expect($databases->first()->name)->toBe('same-server-db');
});

test('database references includes all database types on same server', function () {
    StandalonePostgresql::create([
        'name' => 'test-postgres',
        'image' => 'postgres:15-alpine',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'testdb',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    StandaloneMysql::create([
        'name' => 'test-mysql',
        'image' => 'mysql:8',
        'mysql_user' => 'mysql',
        'mysql_password' => 'password',
        'mysql_root_password' => 'rootpass',
        'mysql_database' => 'testdb',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    $serverId = $this->server->id;
    $eagerLoad = ['destination.server'];
    $databases = collect()
        ->merge($this->environment->postgresqls()->with($eagerLoad)->get())
        ->merge($this->environment->mysqls()->with($eagerLoad)->get())
        ->filter(fn ($db) => $db->destination->server->id === $serverId);

    expect($databases)->toHaveCount(2);
});

test('postgresql internal_db_url uses uuid as host', function () {
    $db = StandalonePostgresql::create([
        'name' => 'test-postgres',
        'image' => 'postgres:15-alpine',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'testdb',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    $url = $db->internal_db_url;

    expect($url)->toContain($db->uuid)
        ->and($url)->toContain('postgres://')
        ->and($url)->toContain(':5432/')
        ->and($url)->toContain('testdb');
});

test('container name for database is the uuid', function () {
    $db = StandalonePostgresql::create([
        'name' => 'test-postgres',
        'image' => 'postgres:15-alpine',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'testdb',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    expect($db->uuid)->not->toBeEmpty();
});

test('container name for application falls back to uuid', function () {
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    $containerName = $application->settings->custom_internal_name ?: $application->uuid;

    expect($containerName)->toBe($application->uuid);
});

test('container name for application uses custom_internal_name when set', function () {
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    $application->settings->custom_internal_name = 'my-custom-name';
    $application->settings->save();
    $application->refresh();

    $containerName = $application->settings->custom_internal_name ?: $application->uuid;

    expect($containerName)->toBe('my-custom-name');
});

test('database references returns empty when no databases on same server', function () {
    $otherServer = Server::factory()->create(['team_id' => $this->team->id]);
    $otherDestination = StandaloneDocker::where('server_id', $otherServer->id)->first();

    StandalonePostgresql::create([
        'name' => 'other-server-db',
        'image' => 'postgres:15-alpine',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'testdb',
        'environment_id' => $this->environment->id,
        'destination_id' => $otherDestination->id,
        'destination_type' => $otherDestination->getMorphClass(),
    ]);

    $serverId = $this->server->id;
    $eagerLoad = ['destination.server'];
    $databases = collect()
        ->merge($this->environment->postgresqls()->with($eagerLoad)->get())
        ->filter(fn ($db) => $db->destination->server->id === $serverId);

    expect($databases)->toHaveCount(0);
});

test('prefillFromDatabase sets key, value and disables buildtime', function () {
    $component = new \App\Livewire\Project\Shared\EnvironmentVariable\Add;
    $component->prefillFromDatabase('DB_HOST', 'some-uuid');

    expect($component->key)->toBe('DB_HOST')
        ->and($component->value)->toBe('some-uuid')
        ->and($component->is_buildtime)->toBeFalse()
        ->and($component->is_runtime)->toBeTrue();
});

test('prefillFromDatabase clears previous state before setting', function () {
    $component = new \App\Livewire\Project\Shared\EnvironmentVariable\Add;
    $component->key = 'OLD_KEY';
    $component->value = 'old_value';
    $component->is_literal = true;
    $component->comment = 'old comment';

    $component->prefillFromDatabase('DB_PASSWORD', 'secret');

    expect($component->key)->toBe('DB_PASSWORD')
        ->and($component->value)->toBe('secret')
        ->and($component->is_literal)->toBeFalse()
        ->and($component->comment)->toBeNull();
});
