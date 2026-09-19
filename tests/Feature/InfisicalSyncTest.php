<?php

use App\Actions\Infisical\SyncEnvironmentSecrets;
use App\Models\InfisicalBinding;
use App\Models\SharedEnvironmentVariable;
use App\Services\Infisical\InfisicalApiException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * Http::fake() registrations accumulate rather than replace one another: an
 * earlier wildcard stub for the same URL pattern always wins, so a test that
 * calls this helper more than once (e.g. to change the response mid-test)
 * needs the previous registrations cleared first.
 */
function resetHttpFakes(): void
{
    $factory = app(HttpFactory::class);
    $property = new ReflectionProperty($factory, 'stubCallbacks');
    $property->setAccessible(true);
    $property->setValue($factory, new Collection);
}

function fakeInfisical(array $secrets): void
{
    resetHttpFakes();

    $payload = [];
    foreach ($secrets as $key => $value) {
        $payload[] = ['secretKey' => $key, 'secretValue' => $value];
    }

    Http::fake([
        '*/api/v1/auth/universal-auth/login' => Http::response(['accessToken' => 'token-123']),
        '*/api/v3/secrets/raw*' => Http::response(['secrets' => $payload]),
    ]);
}

test('it creates environment scoped shared variables owned by the binding', function () {
    fakeInfisical(['DB_PASSWORD' => 'hunter2', 'API_KEY' => 'abc']);
    $binding = InfisicalBinding::factory()->create();

    $result = SyncEnvironmentSecrets::run($binding);

    expect($result['created'])->toBe(2);

    $variable = SharedEnvironmentVariable::where('key', 'DB_PASSWORD')->firstOrFail();
    expect($variable->value)->toBe('hunter2');
    expect($variable->type)->toBe('environment');
    expect($variable->environment_id)->toBe($binding->environment_id);
    expect($variable->team_id)->toBe($binding->connection->team_id);
    expect($variable->infisical_binding_id)->toBe($binding->id);
});

test('it updates an existing value it owns rather than duplicating it', function () {
    fakeInfisical(['DB_PASSWORD' => 'hunter2']);
    $binding = InfisicalBinding::factory()->create();
    SyncEnvironmentSecrets::run($binding);

    fakeInfisical(['DB_PASSWORD' => 'rotated']);
    $result = SyncEnvironmentSecrets::run($binding);

    expect($result['updated'])->toBe(1);
    expect(SharedEnvironmentVariable::where('key', 'DB_PASSWORD')->count())->toBe(1);
    expect(SharedEnvironmentVariable::where('key', 'DB_PASSWORD')->first()->value)->toBe('rotated');
});

test('it deletes variables it owns that disappeared upstream', function () {
    fakeInfisical(['DB_PASSWORD' => 'hunter2', 'API_KEY' => 'abc']);
    $binding = InfisicalBinding::factory()->create();
    SyncEnvironmentSecrets::run($binding);

    fakeInfisical(['DB_PASSWORD' => 'hunter2']);
    $result = SyncEnvironmentSecrets::run($binding);

    expect($result['deleted'])->toBe(1);
    expect(SharedEnvironmentVariable::where('key', 'API_KEY')->exists())->toBeFalse();
});

test('it never touches user owned variables in the same environment', function () {
    $binding = InfisicalBinding::factory()->create();

    $userOwned = SharedEnvironmentVariable::create([
        'key' => 'USER_OWNED',
        'value' => 'keep-me',
        'type' => 'environment',
        'team_id' => $binding->connection->team_id,
        'environment_id' => $binding->environment_id,
    ]);

    fakeInfisical(['DB_PASSWORD' => 'hunter2']);
    SyncEnvironmentSecrets::run($binding);

    $userOwned->refresh();
    expect($userOwned->exists)->toBeTrue();
    expect($userOwned->value)->toBe('keep-me');
    expect($userOwned->infisical_binding_id)->toBeNull();
});

test('it skips keys that are not valid environment variable names', function () {
    fakeInfisical(['VALID_KEY' => 'ok', 'not-a-valid-key' => 'nope']);
    $binding = InfisicalBinding::factory()->create();

    $result = SyncEnvironmentSecrets::run($binding);

    expect($result['created'])->toBe(1);
    expect($result['skipped'])->toBe(['not-a-valid-key']);
    expect(SharedEnvironmentVariable::where('key', 'VALID_KEY')->exists())->toBeTrue();
});

test('it records success metadata on the binding', function () {
    fakeInfisical(['DB_PASSWORD' => 'hunter2']);
    $binding = InfisicalBinding::factory()->create();

    SyncEnvironmentSecrets::run($binding);

    $binding->refresh();
    expect($binding->last_sync_status)->toBe(InfisicalBinding::STATUS_SUCCESS);
    expect($binding->last_sync_error)->toBeNull();
    expect($binding->last_synced_at)->not->toBeNull();
});

test('it records failure metadata and rethrows when infisical is unreachable', function () {
    Http::fake(['*' => Http::response(['message' => 'down'], 500)]);
    $binding = InfisicalBinding::factory()->create();

    expect(fn () => SyncEnvironmentSecrets::run($binding))->toThrow(InfisicalApiException::class);

    $binding->refresh();
    expect($binding->last_sync_status)->toBe(InfisicalBinding::STATUS_FAILED);
    expect($binding->last_sync_error)->not->toBeNull();
});

test('a disabled binding syncs nothing', function () {
    fakeInfisical(['DB_PASSWORD' => 'hunter2']);
    $binding = InfisicalBinding::factory()->create(['is_enabled' => false]);

    $result = SyncEnvironmentSecrets::run($binding);

    expect($result['created'])->toBe(0);
    expect(SharedEnvironmentVariable::count())->toBe(0);
});

test('a hidden secret value aborts the sync instead of blanking the stored value', function () {
    $binding = InfisicalBinding::factory()->create();

    fakeInfisical(['DB_PASSWORD' => 'hunter2']);
    SyncEnvironmentSecrets::run($binding);
    expect(SharedEnvironmentVariable::where('key', 'DB_PASSWORD')->first()->value)->toBe('hunter2');

    resetHttpFakes();
    Http::fake([
        '*/api/v1/auth/universal-auth/login' => Http::response(['accessToken' => 'token-123']),
        '*/api/v3/secrets/raw*' => Http::response(['secrets' => [
            ['secretKey' => 'DB_PASSWORD', 'secretValue' => '', 'secretValueHidden' => true],
        ]]),
    ]);

    expect(fn () => SyncEnvironmentSecrets::run($binding))->toThrow(InfisicalApiException::class);

    // The previously synced value must survive untouched.
    expect(SharedEnvironmentVariable::where('key', 'DB_PASSWORD')->first()->value)->toBe('hunter2');
});

test('a hidden secret never causes the stored row to be deleted', function () {
    $binding = InfisicalBinding::factory()->create();

    fakeInfisical(['API_KEY' => 'abc']);
    SyncEnvironmentSecrets::run($binding);

    resetHttpFakes();
    Http::fake([
        '*/api/v1/auth/universal-auth/login' => Http::response(['accessToken' => 'token-123']),
        '*/api/v3/secrets/raw*' => Http::response(['secrets' => [
            ['secretKey' => 'API_KEY', 'secretValue' => '', 'secretValueHidden' => true],
        ]]),
    ]);

    try {
        SyncEnvironmentSecrets::run($binding);
    } catch (InfisicalApiException) {
        // expected
    }

    expect(SharedEnvironmentVariable::where('key', 'API_KEY')->exists())->toBeTrue();
});
