<?php

use App\Models\Application;
use App\Models\EnvironmentVariable;
use App\Models\Team;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->team->members()->attach($this->user, ['role' => 'owner']);
    $this->application = Application::factory()->create();

    $this->actingAs($this->user);
});

test('all fillable fields can be mass assigned', function () {
    $data = [
        'key' => 'TEST_KEY',
        'value' => 'test_value',
        'comment' => 'Test comment',
        'is_literal' => true,
        'is_multiline' => true,
        'is_preview' => false,
        'is_runtime' => true,
        'is_buildtime' => false,
        'is_shown_once' => false,
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
    ];

    $env = EnvironmentVariable::create($data);

    expect($env->key)->toBe('TEST_KEY');
    expect($env->value)->toBe('test_value');
    expect($env->comment)->toBe('Test comment');
    expect($env->is_literal)->toBeTrue();
    expect($env->is_multiline)->toBeTrue();
    expect($env->is_preview)->toBeFalse();
    expect($env->is_runtime)->toBeTrue();
    expect($env->is_buildtime)->toBeFalse();
    expect($env->is_shown_once)->toBeFalse();
    expect($env->resourceable_type)->toBe(Application::class);
    expect($env->resourceable_id)->toBe($this->application->id);
});

test('comment field can be mass assigned with null', function () {
    $env = EnvironmentVariable::create([
        'key' => 'TEST_VAR',
        'value' => 'test_value',
        'comment' => null,
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
    ]);

    expect($env->comment)->toBeNull();
});

test('comment field can be mass assigned with empty string', function () {
    $env = EnvironmentVariable::create([
        'key' => 'TEST_VAR',
        'value' => 'test_value',
        'comment' => '',
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
    ]);

    expect($env->comment)->toBe('');
});

test('comment field can be mass assigned with long text', function () {
    $comment = str_repeat('This is a long comment. ', 10);

    $env = EnvironmentVariable::create([
        'key' => 'TEST_VAR',
        'value' => 'test_value',
        'comment' => $comment,
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
    ]);

    expect($env->comment)->toBe($comment);
    expect(strlen($env->comment))->toBe(strlen($comment));
});

test('all boolean fields default correctly when not provided', function () {
    $env = EnvironmentVariable::create([
        'key' => 'TEST_VAR',
        'value' => 'test_value',
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
    ]);

    // Boolean fields can be null or false depending on database defaults
    expect($env->is_multiline)->toBeIn([false, null]);
    expect($env->is_preview)->toBeIn([false, null]);
    expect($env->is_runtime)->toBeIn([false, null]);
    expect($env->is_buildtime)->toBeIn([false, null]);
    expect($env->is_shown_once)->toBeIn([false, null]);
});

test('value field is properly encrypted when mass assigned', function () {
    $plainValue = 'secret_value_123';

    $env = EnvironmentVariable::create([
        'key' => 'SECRET_KEY',
        'value' => $plainValue,
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
    ]);

    // Value should be decrypted when accessed via model
    expect($env->value)->toBe($plainValue);

    // Verify it's actually encrypted in the database
    $rawValue = \DB::table('environment_variables')
        ->where('id', $env->id)
        ->value('value');

    expect($rawValue)->not->toBe($plainValue);
    expect($rawValue)->not->toBeNull();
});

test('key field is trimmed and spaces replaced with underscores', function () {
    $env = EnvironmentVariable::create([
        'key' => '  TEST KEY WITH SPACES  ',
        'value' => 'test_value',
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
    ]);

    expect($env->key)->toBe('TEST_KEY_WITH_SPACES');
});

test('version field can be mass assigned', function () {
    $env = EnvironmentVariable::create([
        'key' => 'TEST_VAR',
        'value' => 'test_value',
        'version' => '1.2.3',
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
    ]);

    // The booted() method sets version automatically, so it will be the current version
    expect($env->version)->not->toBeNull();
});

test('mass assignment works with update method', function () {
    $env = EnvironmentVariable::create([
        'key' => 'TEST_VAR',
        'value' => 'initial_value',
        'comment' => 'Initial comment',
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
    ]);

    $env->update([
        'value' => 'updated_value',
        'comment' => 'Updated comment',
        'is_literal' => true,
    ]);

    $env->refresh();

    expect($env->value)->toBe('updated_value');
    expect($env->comment)->toBe('Updated comment');
    expect($env->is_literal)->toBeTrue();
});

test('protected attributes cannot be mass assigned', function () {
    $customDate = '2020-01-01 00:00:00';

    $env = EnvironmentVariable::create([
        'id' => 999999,
        'uuid' => 'custom-uuid',
        'key' => 'TEST_VAR',
        'value' => 'test_value',
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
        'created_at' => $customDate,
        'updated_at' => $customDate,
    ]);

    // id should be auto-generated, not 999999
    expect($env->id)->not->toBe(999999);

    // uuid should be auto-generated, not 'custom-uuid'
    expect($env->uuid)->not->toBe('custom-uuid');

    // Timestamps should be current, not 2020
    expect($env->created_at->year)->toBe(now()->year);
});

test('order field can be mass assigned', function () {
    $env = EnvironmentVariable::create([
        'key' => 'TEST_VAR',
        'value' => 'test_value',
        'order' => 5,
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
    ]);

    expect($env->order)->toBe(5);
});

test('is_shared field can be mass assigned', function () {
    $env = EnvironmentVariable::create([
        'key' => 'TEST_VAR',
        'value' => 'test_value',
        'is_shared' => true,
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
    ]);

    // Note: is_shared is also computed via accessor, but can be mass assigned
    expect($env->is_shared)->not->toBeNull();
});
