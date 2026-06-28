<?php

use App\Models\Application;
use App\Models\EnvironmentVariable;
use App\Models\Team;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->team->members()->attach($this->user, ['role' => 'owner']);
    $this->application = Application::factory()->create([
        'team_id' => $this->team->id,
    ]);

    $this->actingAs($this->user);
});

test('environment variable can be created with comment', function () {
    $env = EnvironmentVariable::create([
        'key' => 'TEST_VAR',
        'value' => 'test_value',
        'comment' => 'This is a test environment variable',
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
    ]);

    expect($env->comment)->toBe('This is a test environment variable');
    expect($env->key)->toBe('TEST_VAR');
    expect($env->value)->toBe('test_value');
});

test('environment variable comment is optional', function () {
    $env = EnvironmentVariable::create([
        'key' => 'TEST_VAR',
        'value' => 'test_value',
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
    ]);

    expect($env->comment)->toBeNull();
    expect($env->key)->toBe('TEST_VAR');
});

test('environment variable comment can be updated', function () {
    $env = EnvironmentVariable::create([
        'key' => 'TEST_VAR',
        'value' => 'test_value',
        'comment' => 'Initial comment',
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
    ]);

    $env->comment = 'Updated comment';
    $env->save();

    $env->refresh();
    expect($env->comment)->toBe('Updated comment');
});

test('environment variable comment is preserved when updating value', function () {
    $env = EnvironmentVariable::create([
        'key' => 'TEST_VAR',
        'value' => 'initial_value',
        'comment' => 'Important variable for testing',
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
    ]);

    $env->value = 'new_value';
    $env->save();

    $env->refresh();
    expect($env->value)->toBe('new_value');
    expect($env->comment)->toBe('Important variable for testing');
});

test('environment variable comment is copied to preview environment', function () {
    $env = EnvironmentVariable::create([
        'key' => 'TEST_VAR',
        'value' => 'test_value',
        'comment' => 'Test comment',
        'is_preview' => false,
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
    ]);

    // The model's created() event listener automatically creates a preview version
    $previewEnv = EnvironmentVariable::where('key', 'TEST_VAR')
        ->where('resourceable_id', $this->application->id)
        ->where('is_preview', true)
        ->first();

    expect($previewEnv)->not->toBeNull();
    expect($previewEnv->comment)->toBe('Test comment');
});

test('parseEnvFormatToArray preserves values without inline comments', function () {
    $input = "KEY1=value1\nKEY2=value2";
    $result = parseEnvFormatToArray($input);

    expect($result)->toBe([
        'KEY1' => ['value' => 'value1', 'comment' => null],
        'KEY2' => ['value' => 'value2', 'comment' => null],
    ]);
});

test('developer view format does not break with comment-like values', function () {
    // Values that contain # but shouldn't be treated as comments when quoted
    $env1 = EnvironmentVariable::create([
        'key' => 'HASH_VAR',
        'value' => 'value_with_#_in_it',
        'comment' => 'Contains hash symbol',
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
    ]);

    expect($env1->value)->toBe('value_with_#_in_it');
    expect($env1->comment)->toBe('Contains hash symbol');
});

test('environment variable comment can store up to 256 characters', function () {
    $comment = str_repeat('a', 256);
    $env = EnvironmentVariable::create([
        'key' => 'TEST_VAR',
        'value' => 'test_value',
        'comment' => $comment,
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
    ]);

    expect($env->comment)->toBe($comment);
    expect(strlen($env->comment))->toBe(256);
});

test('environment variable comment cannot exceed 256 characters via Livewire', function () {
    $env = EnvironmentVariable::create([
        'key' => 'TEST_VAR',
        'value' => 'test_value',
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
    ]);

    $longComment = str_repeat('a', 257);

    Livewire::test(\App\Livewire\Project\Shared\EnvironmentVariable\Show::class, ['env' => $env, 'type' => 'application'])
        ->set('comment', $longComment)
        ->call('submit')
        ->assertHasErrors(['comment' => 'max']);
});

test('bulk update preserves existing comments when no inline comment provided', function () {
    // Create existing variable with a manually-entered comment
    $env = EnvironmentVariable::create([
        'key' => 'DATABASE_URL',
        'value' => 'postgres://old-host',
        'comment' => 'Production database',
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
    ]);

    // User switches to Developer view and pastes new value without inline comment
    $bulkContent = "DATABASE_URL=postgres://new-host\nOTHER_VAR=value";

    Livewire::test(\App\Livewire\Project\Shared\EnvironmentVariable\All::class, [
        'resource' => $this->application,
        'type' => 'application',
    ])
        ->set('variables', $bulkContent)
        ->call('submit');

    // Refresh the environment variable
    $env->refresh();

    // The value should be updated
    expect($env->value)->toBe('postgres://new-host');

    // The manually-entered comment should be PRESERVED
    expect($env->comment)->toBe('Production database');
});

test('bulk update overwrites existing comments when inline comment provided', function () {
    // Create existing variable with a comment
    $env = EnvironmentVariable::create([
        'key' => 'API_KEY',
        'value' => 'old-key',
        'comment' => 'Old comment',
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
    ]);

    // User pastes new value WITH inline comment
    $bulkContent = 'API_KEY=new-key #Updated production key';

    Livewire::test(\App\Livewire\Project\Shared\EnvironmentVariable\All::class, [
        'resource' => $this->application,
        'type' => 'application',
    ])
        ->set('variables', $bulkContent)
        ->call('submit');

    // Refresh the environment variable
    $env->refresh();

    // The value should be updated
    expect($env->value)->toBe('new-key');

    // The comment should be OVERWRITTEN with the inline comment
    expect($env->comment)->toBe('Updated production key');
});

test('bulk update handles mixed inline and stored comments correctly', function () {
    // Create two variables with comments
    $env1 = EnvironmentVariable::create([
        'key' => 'VAR_WITH_COMMENT',
        'value' => 'value1',
        'comment' => 'Existing comment 1',
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
    ]);

    $env2 = EnvironmentVariable::create([
        'key' => 'VAR_WITHOUT_COMMENT',
        'value' => 'value2',
        'comment' => 'Existing comment 2',
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
    ]);

    // Bulk paste: one with inline comment, one without
    $bulkContent = "VAR_WITH_COMMENT=new_value1 #New inline comment\nVAR_WITHOUT_COMMENT=new_value2";

    Livewire::test(\App\Livewire\Project\Shared\EnvironmentVariable\All::class, [
        'resource' => $this->application,
        'type' => 'application',
    ])
        ->set('variables', $bulkContent)
        ->call('submit');

    // Refresh both variables
    $env1->refresh();
    $env2->refresh();

    // First variable: comment should be overwritten with inline comment
    expect($env1->value)->toBe('new_value1');
    expect($env1->comment)->toBe('New inline comment');

    // Second variable: comment should be preserved
    expect($env2->value)->toBe('new_value2');
    expect($env2->comment)->toBe('Existing comment 2');
});

test('bulk update creates new variables with inline comments', function () {
    // Bulk paste creates new variables, some with inline comments
    $bulkContent = "NEW_VAR1=value1 #Comment for var1\nNEW_VAR2=value2\nNEW_VAR3=value3 #Comment for var3";

    Livewire::test(\App\Livewire\Project\Shared\EnvironmentVariable\All::class, [
        'resource' => $this->application,
        'type' => 'application',
    ])
        ->set('variables', $bulkContent)
        ->call('submit');

    // Check that variables were created with correct comments
    $var1 = EnvironmentVariable::where('key', 'NEW_VAR1')
        ->where('resourceable_id', $this->application->id)
        ->first();
    $var2 = EnvironmentVariable::where('key', 'NEW_VAR2')
        ->where('resourceable_id', $this->application->id)
        ->first();
    $var3 = EnvironmentVariable::where('key', 'NEW_VAR3')
        ->where('resourceable_id', $this->application->id)
        ->first();

    expect($var1->value)->toBe('value1');
    expect($var1->comment)->toBe('Comment for var1');

    expect($var2->value)->toBe('value2');
    expect($var2->comment)->toBeNull();

    expect($var3->value)->toBe('value3');
    expect($var3->comment)->toBe('Comment for var3');
});
