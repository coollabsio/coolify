<?php

use App\Models\Application;
use App\Models\EnvironmentVariable;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->team->members()->attach($this->user, ['role' => 'owner']);
    $this->application = Application::factory()->create();

    $this->actingAs($this->user);
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
    expect($env->is_shown_once)->toBeIn([false, null]);

    // New variables are available at runtime and build time unless configured otherwise
    expect($env->is_runtime)->toBeTrue();
    expect($env->is_buildtime)->toBeTrue();
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
    $rawValue = DB::table('environment_variables')
        ->where('id', $env->id)
        ->value('value');

    expect($rawValue)->not->toBe($plainValue);
    expect($rawValue)->not->toBeNull();
});

test('key field is trimmed', function () {
    $env = EnvironmentVariable::create([
        'key' => '  TEST_KEY  ',
        'value' => 'test_value',
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
    ]);

    expect($env->key)->toBe('TEST_KEY');
});

test('key field with inner spaces is rejected', function () {
    EnvironmentVariable::create([
        'key' => '  TEST KEY WITH SPACES  ',
        'value' => 'test_value',
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
    ]);
})->throws(InvalidArgumentException::class);
