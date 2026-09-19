<?php

use App\Actions\Infisical\SyncEnvironmentSecrets;
use App\Models\InfisicalBinding;
use App\Models\SharedEnvironmentVariable;
use App\Services\Infisical\InfisicalApiException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ResponseSequence;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * Holds the response sequence for the secrets endpoint across multiple
 * fakeInfisical()/fakeInfisicalRawSecrets() calls within a single test.
 *
 * Http::fake() registrations accumulate rather than replace one another: an
 * earlier wildcard stub for a URL always wins over a later, overlapping one.
 * A test that needs a different response on a later call to the same
 * endpoint (e.g. a rotated secret, or a hidden-value response) must instead
 * push successive responses onto one shared Http::fakeSequence() instance.
 */
function infisicalHttpState(): object
{
    static $state;

    return $state ??= new class
    {
        public ?ResponseSequence $secretsSequence = null;
    };
}

beforeEach(function () {
    infisicalHttpState()->secretsSequence = null;
});

/**
 * @param  array<int, array<string, mixed>>  $secrets  Raw Infisical secret entries.
 */
function fakeInfisicalRawSecrets(array $secrets): void
{
    $state = infisicalHttpState();
    $state->secretsSequence ??= Http::fakeSequence('*/api/v3/secrets/raw*');
    $state->secretsSequence->push(['secrets' => $secrets]);
}

function fakeInfisical(array $secrets): void
{
    Http::fake([
        '*/api/v1/auth/universal-auth/login' => Http::response(['accessToken' => 'token-123']),
    ]);

    $payload = [];
    foreach ($secrets as $key => $value) {
        $payload[] = ['secretKey' => $key, 'secretValue' => $value];
    }

    fakeInfisicalRawSecrets($payload);
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

    fakeInfisicalRawSecrets([
        ['secretKey' => 'DB_PASSWORD', 'secretValue' => '', 'secretValueHidden' => true],
    ]);

    expect(fn () => SyncEnvironmentSecrets::run($binding))->toThrow(InfisicalApiException::class);

    // The previously synced value must survive untouched.
    expect(SharedEnvironmentVariable::where('key', 'DB_PASSWORD')->first()->value)->toBe('hunter2');
});

test('a hidden secret never causes the stored row to be deleted', function () {
    $binding = InfisicalBinding::factory()->create();

    fakeInfisical(['API_KEY' => 'abc']);
    SyncEnvironmentSecrets::run($binding);

    fakeInfisicalRawSecrets([
        ['secretKey' => 'API_KEY', 'secretValue' => '', 'secretValueHidden' => true],
    ]);

    try {
        SyncEnvironmentSecrets::run($binding);
    } catch (InfisicalApiException) {
        // expected
    }

    expect(SharedEnvironmentVariable::where('key', 'API_KEY')->exists())->toBeTrue();
});

test('a key colliding with a user owned row is shadowed, not adopted or overwritten, and the rest of the sync still succeeds', function () {
    $binding = InfisicalBinding::factory()->create();

    $userOwned = SharedEnvironmentVariable::create([
        'key' => 'DB_PASSWORD',
        'value' => 'user-value',
        'type' => 'environment',
        'team_id' => $binding->connection->team_id,
        'environment_id' => $binding->environment_id,
    ]);

    fakeInfisical(['DB_PASSWORD' => 'hunter2', 'API_KEY' => 'abc']);

    $result = SyncEnvironmentSecrets::run($binding);

    expect($result['shadowed'])->toBe(['DB_PASSWORD']);
    expect($result['created'])->toBe(1);

    $userOwned->refresh();
    expect($userOwned->exists)->toBeTrue();
    expect($userOwned->value)->toBe('user-value');
    expect($userOwned->infisical_binding_id)->toBeNull();

    expect(SharedEnvironmentVariable::where('key', 'API_KEY')->exists())->toBeTrue();

    $binding->refresh();
    expect($binding->last_sync_status)->toBe(InfisicalBinding::STATUS_SUCCESS);
});

test('an empty upstream response does not wipe the rows the binding already owns', function () {
    $binding = InfisicalBinding::factory()->create();

    fakeInfisical(['DB_PASSWORD' => 'hunter2', 'API_KEY' => 'abc']);
    SyncEnvironmentSecrets::run($binding);
    expect(SharedEnvironmentVariable::where('infisical_binding_id', $binding->id)->count())->toBe(2);

    fakeInfisicalRawSecrets([]);
    $result = SyncEnvironmentSecrets::run($binding);

    expect($result['aborted'])->toBeTrue();
    expect($result['deleted'])->toBe(0);
    expect(SharedEnvironmentVariable::where('infisical_binding_id', $binding->id)->count())->toBe(2);
    expect(SharedEnvironmentVariable::where('key', 'DB_PASSWORD')->first()->value)->toBe('hunter2');
});

test('an empty upstream response records a failure on the binding', function () {
    $binding = InfisicalBinding::factory()->create();

    fakeInfisical(['DB_PASSWORD' => 'hunter2']);
    SyncEnvironmentSecrets::run($binding);

    fakeInfisicalRawSecrets([]);
    SyncEnvironmentSecrets::run($binding);

    $binding->refresh();
    expect($binding->last_sync_status)->toBe(InfisicalBinding::STATUS_FAILED);
    expect($binding->last_sync_error)->toContain('no usable secrets');
});

test('an empty upstream response on a binding that owns nothing is a plain success', function () {
    $binding = InfisicalBinding::factory()->create();

    fakeInfisical([]);
    $result = SyncEnvironmentSecrets::run($binding);

    expect($result['aborted'])->toBeFalse();

    $binding->refresh();
    expect($binding->last_sync_status)->toBe(InfisicalBinding::STATUS_SUCCESS);
});
