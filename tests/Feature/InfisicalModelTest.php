<?php

use App\Models\Environment;
use App\Models\InfisicalBinding;
use App\Models\InfisicalConnection;
use App\Models\SharedEnvironmentVariable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('connection credentials are encrypted at rest and hidden from serialization', function () {
    $connection = InfisicalConnection::factory()->create([
        'client_id' => 'client-abc',
        'client_secret' => 'secret-xyz',
    ]);

    expect($connection->client_secret)->toBe('secret-xyz');

    $raw = SharedEnvironmentVariable::query()
        ->getConnection()
        ->table('infisical_connections')
        ->where('id', $connection->id)
        ->value('client_secret');

    expect($raw)->not->toBe('secret-xyz');
    expect($connection->toArray())->not->toHaveKey('client_secret');
    expect($connection->toArray())->not->toHaveKey('client_id');
});

test('credentials are trimmed on save', function () {
    $connection = InfisicalConnection::factory()->create([
        'client_id' => "  client-abc\n",
        'client_secret' => '  secret-xyz  ',
    ]);

    expect($connection->client_id)->toBe('client-abc');
    expect($connection->client_secret)->toBe('secret-xyz');
});

test('a binding belongs to a connection and an environment', function () {
    $binding = InfisicalBinding::factory()->create();

    expect($binding->connection)->toBeInstanceOf(InfisicalConnection::class);
    expect($binding->environment)->toBeInstanceOf(Environment::class);
    expect($binding->secret_path)->toBe('/');
    expect($binding->is_enabled)->toBeTrue();
});

test('only one binding may exist per environment', function () {
    $binding = InfisicalBinding::factory()->create();

    expect(fn () => InfisicalBinding::factory()->create([
        'environment_id' => $binding->environment_id,
    ]))->toThrow(QueryException::class);
});

test('a shared variable can be owned by a binding', function () {
    $binding = InfisicalBinding::factory()->create();

    $variable = SharedEnvironmentVariable::create([
        'key' => 'DB_PASSWORD',
        'value' => 'hunter2',
        'type' => 'environment',
        'team_id' => $binding->connection->team_id,
        'environment_id' => $binding->environment_id,
        'infisical_binding_id' => $binding->id,
    ]);

    expect($variable->infisicalBinding->id)->toBe($binding->id);
    expect($binding->sharedVariables()->pluck('key')->all())->toBe(['DB_PASSWORD']);
});
