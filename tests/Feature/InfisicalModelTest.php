<?php

use App\Models\EnvironmentVariable;
use App\Models\InfisicalConnection;
use App\Models\SharedEnvironmentVariable;
use App\Models\Team;
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

it('allows only one connection per team', function () {
    $team = Team::factory()->create();
    InfisicalConnection::factory()->create(['team_id' => $team->id]);

    expect(fn () => InfisicalConnection::factory()->create(['team_id' => $team->id]))
        ->toThrow(QueryException::class);
});

it('defaults to disabled and unadopted', function () {
    $connection = InfisicalConnection::factory()->create();

    expect($connection->is_enabled)->toBeFalse()
        ->and($connection->adopted_at)->toBeNull();
});

it('marks variables as infisical managed on both variable tables', function () {
    $team = Team::factory()->create();

    $resourceVar = EnvironmentVariable::factory()->create([
        'is_infisical_managed' => true,
        'infisical_path' => '/shop-api/api-server/',
    ]);
    $sharedVar = SharedEnvironmentVariable::factory()->create([
        'team_id' => $team->id,
        'is_infisical_managed' => true,
        'infisical_path' => '/shop-api/',
    ]);

    expect($resourceVar->fresh()->is_infisical_managed)->toBeTrue()
        ->and($resourceVar->fresh()->infisical_path)->toBe('/shop-api/api-server/')
        ->and($sharedVar->fresh()->is_infisical_managed)->toBeTrue()
        ->and($sharedVar->fresh()->infisical_path)->toBe('/shop-api/');
});
