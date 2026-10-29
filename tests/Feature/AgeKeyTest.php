<?php

use App\Models\AgeKey;
use App\Models\ScheduledDatabaseBackup;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

const TEST_AGE_PRIVATE_KEY = 'AGE-SECRET-KEY-1QYQSZQGPQYQSZQGPQYQSZQGPQYQSZQGPQYQSZQGPQYQSZQGPQYQSZQGPQ2XQ9VA';
const TEST_AGE_PUBLIC_KEY = 'age1qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq';

test('age key stores a valid public key', function () {
    $team = Team::factory()->create();
    $key = AgeKey::create([
        'name' => 'Test key',
        'public_key' => TEST_AGE_PUBLIC_KEY,
        'team_id' => $team->id,
    ]);

    expect($key->public_key)->toBe(TEST_AGE_PUBLIC_KEY);
});

test('age key rejects a value that is not a valid age recipient', function () {
    $team = Team::factory()->create();

    expect(fn () => AgeKey::create([
        'name' => 'Bad key',
        'public_key' => 'not-an-age-key',
        'team_id' => $team->id,
    ]))->toThrow(ValidationException::class);
});

test('age key rejects a private key mistakenly pasted into the public key field', function () {
    $team = Team::factory()->create();

    expect(fn () => AgeKey::create([
        'name' => 'Bad key',
        'public_key' => TEST_AGE_PRIVATE_KEY,
        'team_id' => $team->id,
    ]))->toThrow(ValidationException::class);
});

test('age key model has no private key column or cast', function () {
    $model = new AgeKey;

    expect($model->getFillable())->not->toContain('private_key');
    expect($model->getCasts())->not->toHaveKey('private_key');
});

test('age key safeDelete refuses to delete a key in use by a backup schedule', function () {
    $team = Team::factory()->create();
    $key = AgeKey::create([
        'name' => 'Test key',
        'public_key' => TEST_AGE_PUBLIC_KEY,
        'team_id' => $team->id,
    ]);

    ScheduledDatabaseBackup::create([
        'frequency' => '0 0 * * *',
        'save_s3' => false,
        'encryption_enabled' => true,
        'age_key_id' => $key->id,
        'database_type' => 'App\Models\StandalonePostgresql',
        'database_id' => 1,
        'team_id' => $team->id,
    ]);

    expect($key->safeDelete())->toBeFalse();
    expect(AgeKey::find($key->id))->not->toBeNull();
});

test('age key safeDelete removes an unused key', function () {
    $team = Team::factory()->create();
    $key = AgeKey::create([
        'name' => 'Test key',
        'public_key' => TEST_AGE_PUBLIC_KEY,
        'team_id' => $team->id,
    ]);

    expect($key->safeDelete())->toBeTrue();
    expect(AgeKey::find($key->id))->toBeNull();
});

test('generateNewKeyPair parses age-keygen output and returns the private key only transiently', function () {
    Process::fake([
        'age-keygen' => Process::result(
            output: "# created: 2026-09-15T00:00:00Z\n# public key: ".TEST_AGE_PUBLIC_KEY."\n".TEST_AGE_PRIVATE_KEY."\n"
        ),
    ]);

    $pair = AgeKey::generateNewKeyPair();

    expect($pair['private_key'])->toBe(TEST_AGE_PRIVATE_KEY);
    expect($pair['public_key'])->toBe(TEST_AGE_PUBLIC_KEY);
    expect($pair)->toHaveKeys(['name', 'description']);
});

test('generateNewKeyPair throws when age-keygen fails', function () {
    Process::fake([
        'age-keygen' => Process::result(exitCode: 1, errorOutput: 'age-keygen: command not found'),
    ]);

    expect(fn () => AgeKey::generateNewKeyPair())->toThrow(Exception::class, 'Failed to generate age key pair');
});

test('isValidPublicKey accepts age1 recipients and rejects everything else', function (string $value, bool $expected) {
    expect(AgeKey::isValidPublicKey($value))->toBe($expected);
})->with([
    'valid public key' => [TEST_AGE_PUBLIC_KEY, true],
    'private key' => [TEST_AGE_PRIVATE_KEY, false],
    'garbage' => ['not-a-key', false],
    'empty' => ['', false],
]);
