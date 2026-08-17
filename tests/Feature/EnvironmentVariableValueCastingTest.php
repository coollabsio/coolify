<?php

use App\Models\Application;
use App\Models\EnvironmentVariable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('stores null environment variable values as database null', function () {
    $env = EnvironmentVariable::create([
        'key' => 'NULL_VALUE',
        'value' => null,
        'resourceable_type' => Application::class,
        'resourceable_id' => 1,
    ]);

    $rawValue = DB::table('environment_variables')
        ->where('id', $env->id)
        ->value('value');

    expect($rawValue)->toBeNull();

    $env->refresh();
    expect($env->value)->toBeNull();
});

it('preserves intentional empty string environment variable values', function () {
    $env = EnvironmentVariable::create([
        'key' => 'EMPTY_STRING_VALUE',
        'value' => '',
        'resourceable_type' => Application::class,
        'resourceable_id' => 1,
    ]);

    $rawValue = DB::table('environment_variables')
        ->where('id', $env->id)
        ->value('value');

    expect($rawValue)->not->toBeNull()
        ->and(decrypt($rawValue))->toBe('');

    $env->refresh();
    expect($env->value)->toBe('');
});
