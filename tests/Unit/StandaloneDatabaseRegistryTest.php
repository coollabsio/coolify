<?php

use App\Models\StandaloneDocker;
use Illuminate\Database\Eloquent\Model;

/**
 * Guards STANDALONE_DATABASE_MODELS against drift.
 *
 * MCP and API endpoints rely on this registry for team-scoped UUID lookups.
 * If a new App\Models\Standalone* model lands without a registry entry, the
 * helpers in bootstrap/helpers/shared.php silently fail to resolve it.
 */
test('STANDALONE_DATABASE_MODELS contains every Standalone* model on disk', function () {
    $files = glob(dirname(__DIR__, 2).'/app/Models/Standalone*.php');
    expect($files)->not->toBeEmpty();

    $onDisk = collect($files)
        ->map(fn (string $path) => 'App\\Models\\'.basename($path, '.php'))
        ->reject(fn (string $class) => $class === StandaloneDocker::class)
        ->sort()
        ->values()
        ->all();

    $registered = collect(STANDALONE_DATABASE_MODELS)->values()->sort()->values()->all();

    expect($registered)->toBe(
        $onDisk,
        'STANDALONE_DATABASE_MODELS in bootstrap/helpers/constants.php is out of sync with the App\\Models\\Standalone* classes on disk. '
        .'Add the missing model(s) to the registry (and to DATABASE_TYPES) so MCP/API helpers can resolve them.'
    );
});

test('STANDALONE_DATABASE_MODELS keys mirror DATABASE_TYPES', function () {
    expect(array_keys(STANDALONE_DATABASE_MODELS))->toEqualCanonicalizing(DATABASE_TYPES);
});

test('every STANDALONE_DATABASE_MODELS entry is an Eloquent model with whereUuid scope', function () {
    foreach (STANDALONE_DATABASE_MODELS as $slug => $modelClass) {
        expect(class_exists($modelClass))->toBeTrue("{$slug} maps to non-existent class {$modelClass}");
        expect(is_subclass_of($modelClass, Model::class))
            ->toBeTrue("{$modelClass} is not an Eloquent model");
        expect(method_exists($modelClass, 'team'))
            ->toBeTrue("{$modelClass} is missing team() accessor required by queryDatabaseByUuidWithinTeam()");
    }
});
