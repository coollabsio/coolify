<?php

use App\Models\EnvironmentVariable;

test('fillable array contains all fields used in mass assignment across codebase', function () {
    $model = new EnvironmentVariable;
    $fillable = $model->getFillable();

    // Core identification
    expect($fillable)->toContain('key')
        ->toContain('value')
        ->toContain('comment');

    // Polymorphic relationship
    expect($fillable)->toContain('resourceable_type')
        ->toContain('resourceable_id');

    // Boolean flags — all used in create/firstOrCreate/updateOrCreate calls
    expect($fillable)->toContain('is_preview')
        ->toContain('is_multiline')
        ->toContain('is_literal')
        ->toContain('is_runtime')
        ->toContain('is_buildtime')
        ->toContain('is_shown_once')
        ->toContain('is_shared')
        ->toContain('is_required');

    // Metadata
    expect($fillable)->toContain('version')
        ->toContain('order');
});

test('is_required can be mass assigned', function () {
    $model = new EnvironmentVariable;
    $model->fill(['is_required' => true]);

    expect($model->is_required)->toBeTrue();
});

test('all boolean flags can be mass assigned', function () {
    $booleanFlags = [
        'is_preview',
        'is_multiline',
        'is_literal',
        'is_runtime',
        'is_buildtime',
        'is_shown_once',
        'is_required',
    ];

    $model = new EnvironmentVariable;
    $model->fill(array_fill_keys($booleanFlags, true));

    foreach ($booleanFlags as $flag) {
        expect($model->$flag)->toBeTrue("Expected {$flag} to be mass assignable and set to true");
    }

    // is_shared has a computed getter derived from the value field,
    // so verify it's fillable via the underlying attributes instead
    $model2 = new EnvironmentVariable;
    $model2->fill(['is_shared' => true]);
    expect($model2->getAttributes())->toHaveKey('is_shared');
});

test('non-fillable fields are rejected by mass assignment', function () {
    $model = new EnvironmentVariable;
    $model->fill(['id' => 999, 'uuid' => 'injected', 'created_at' => 'injected']);

    expect($model->id)->toBeNull()
        ->and($model->uuid)->toBeNull()
        ->and($model->created_at)->toBeNull();
});
