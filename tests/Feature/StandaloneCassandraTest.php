<?php

use App\Enums\NewDatabaseTypes;
use App\Models\Environment;
use App\Models\LocalPersistentVolume;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneCassandra;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->first();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
});

test('create_standalone_cassandra creates a database with sensible defaults', function () {
    $database = create_standalone_cassandra($this->environment->id, $this->destination);

    expect($database->exists)->toBeTrue()
        ->and($database->name)->toStartWith('cassandra-database-')
        ->and($database->image)->toBe('cassandra:5.0')
        ->and($database->cassandra_admin_user)->toBe('cassandra')
        ->and($database->cassandra_admin_password)->not->toBeEmpty()
        ->and($database->environment_id)->toBe($this->environment->id)
        ->and($database->destination_id)->toBe($this->destination->id)
        ->and($database->destination_type)->toBe($this->destination->getMorphClass());
});

test('create_standalone_cassandra registers a persistent volume', function () {
    $database = create_standalone_cassandra($this->environment->id, $this->destination);

    $volume = LocalPersistentVolume::where('resource_id', $database->id)
        ->where('resource_type', $database->getMorphClass())
        ->first();

    expect($volume)->not->toBeNull()
        ->and($volume->name)->toBe('cassandra-data-'.$database->uuid)
        ->and($volume->mount_path)->toBe('/var/lib/cassandra');
});

test('create_standalone_cassandra honors extra data', function () {
    $database = create_standalone_cassandra($this->environment->id, $this->destination, [
        'name' => 'my-cassandra',
        'image' => 'cassandra:5.0',
        'cassandra_admin_user' => 'admin',
    ]);

    expect($database->name)->toBe('my-cassandra')
        ->and($database->image)->toBe('cassandra:5.0')
        ->and($database->cassandra_admin_user)->toBe('admin');
});

test('standalone cassandra exposes type and registry entries', function () {
    $database = new StandaloneCassandra;

    expect($database->type())->toBe('standalone-cassandra')
        ->and($database->getMorphClass())->toBe(StandaloneCassandra::class)
        ->and(STANDALONE_DATABASE_MODELS)->toHaveKey('cassandra', StandaloneCassandra::class)
        ->and(DATABASE_TYPES)->toContain('cassandra')
        ->and(NewDatabaseTypes::CASSANDRA->value)->toBe('cassandra');
});

test('standalone cassandra builds internal and external db urls', function () {
    $database = create_standalone_cassandra($this->environment->id, $this->destination, [
        'cassandra_admin_user' => 'cassandra',
        'cassandra_admin_password' => 'secret-password',
        'is_public' => true,
        'public_port' => 9042,
    ]);

    expect($database->internal_db_url)->toContain('cassandra://')
        ->and($database->internal_db_url)->toContain('@')
        ->and($database->external_db_url)->toContain(':9042');
});

test('standalone cassandra hides credentials in default serialization', function () {
    $database = create_standalone_cassandra($this->environment->id, $this->destination);

    $serialized = $database->toArray();

    expect($serialized)->not->toHaveKey('cassandra_admin_password');
});
