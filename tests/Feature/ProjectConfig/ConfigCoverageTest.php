<?php

use App\Services\ProjectConfig\AdapterRegistry;
use App\Services\ProjectConfig\FieldClass;
use App\Services\ProjectConfig\FieldMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * @return list<string> "table.column" entries that have no field decision
 */
function coverageTestMissingColumns(FieldMap $map): array
{
    $missing = [];
    foreach ($map->tables() as $table) {
        foreach (Schema::getColumnListing($table) as $column) {
            if (! $map->decides($table, $column)) {
                $missing[] = "{$table}.{$column}";
            }
        }
    }

    return $missing;
}

it('gives every column of every adapter a field class', function () {
    foreach (app(AdapterRegistry::class)->all() as $adapter) {
        $maps = [$adapter->fieldMap(), ...array_values($adapter->childTables())];
        foreach ($maps as $map) {
            expect(coverageTestMissingColumns($map))->toBe([], "{$adapter->type()} has columns with no decision");
        }
    }
});

it('requires a reason for every field that the file does not manage', function () {
    foreach (app(AdapterRegistry::class)->all() as $adapter) {
        foreach ([$adapter->fieldMap(), ...array_values($adapter->childTables())] as $map) {
            foreach ($map->fields() as $field) {
                if ($field->class !== FieldClass::Managed) {
                    expect($field->reason)->not->toBeEmpty("{$field->column} needs a reason");
                }
            }
        }
    }
});

it('keeps the published schema in sync with the field maps', function () {
    $published = json_decode(file_get_contents(public_path('schemas/coolify.v1.json')), true);

    expect($published)->toBe(app(AdapterRegistry::class)->schema());
});

it('lists every resource type in the schema', function () {
    $schema = app(AdapterRegistry::class)->schema();

    expect($schema['properties']['version'])->toBe(['const' => 1])
        ->and($schema['additionalProperties'])->toBeFalse()
        ->and(array_keys($schema['properties']))->toContain('resources', 'environments', 'moved', 'unmanage');
});

it('reports a column without a decision', function () {
    $map = new FieldMap('environment_config_links', []);

    expect(coverageTestMissingColumns($map))->toContain('environment_config_links.branch')
        ->not->toContain('environment_config_links.id');
});
