<?php

namespace Tests\Support;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Gives every column of a record a value that is not the default, so a value that a server transfer
 * loses shows up in ServerTransferSnapshot::diff(). Keys, foreign keys, and $keep columns stay as they are.
 */
final class ServerTransferFuzzer
{
    private const KEPT = ['id', 'uuid', 'created_at', 'updated_at', 'deleted_at', 'team_id', 'created_by'];

    /**
     * @param  list<string>  $keep
     * @param  array<string, mixed>  $values  Values to use instead of generated ones.
     */
    public static function fill(Model $model, array $keep = [], array $values = []): Model
    {
        $casts = $model->getCasts();
        $fuzzed = [];
        foreach (Schema::getColumns($model->getTable()) as $column) {
            $name = $column['name'];
            $morph = preg_replace('/_(type|id)$/', '', $name);
            if (in_array($name, [...self::KEPT, ...$keep], true) || isset(ServerTransferSnapshot::FOREIGN_KEYS[$name])
                || (in_array($morph, ServerTransferSnapshot::MORPHS, true) && Schema::hasColumn($model->getTable(), "{$morph}_type"))) {
                continue;
            }
            $value = self::value($name, strtolower($column['type_name']), (string) ($casts[$name] ?? ''), $column['default'], $model->getAttributes()[$name] ?? null);
            // Text must fit the column, such as character varying(10).
            $fuzzed[$name] = is_string($value) && preg_match('/char[a-z ]*\((\d+)\)/', (string) $column['type'], $length) ? substr($value, 0, (int) $length[1]) : $value;
        }
        $model->forceFill([...$fuzzed, ...$values]);
        $model->saveQuietly();

        return $model;
    }

    private static function value(string $name, string $type, string $cast, mixed $default, mixed $current): mixed
    {
        $default = strtolower(trim((string) $default, "'"));
        $enum = explode(':', $cast)[0];
        if (enum_exists($enum) && is_subclass_of($enum, BackedEnum::class)) {
            $cases = $enum::cases();
            $other = collect($cases)->first(fn (BackedEnum $case) => $case->value !== ($current instanceof BackedEnum ? $current->value : ($current ?? $default)));

            return ($other ?? $cases[0])->value;
        }

        return match (true) {
            str_contains($cast, 'array') || str_contains($cast, 'json') || str_contains($cast, 'collection') || str_contains($cast, 'Schemaless') || str_contains($type, 'json') => ['fuzz' => $name],
            in_array($cast, ['bool', 'boolean'], true) || str_contains($type, 'bool') || in_array($default, ['true', 'false'], true) => ! filter_var($current ?? $default, FILTER_VALIDATE_BOOLEAN),
            str_contains($cast, 'date') || str_contains($type, 'timestamp') || str_contains($type, 'date') || (str_ends_with($name, '_at') && ! str_contains($type, 'int')) => '2031-02-03 04:05:06',
            in_array($cast, ['int', 'integer'], true) || str_contains($type, 'int') => (int) ($current ?? $default) + 7,
            in_array($cast, ['float', 'double', 'decimal'], true) || in_array($type, ['numeric', 'float4', 'float8', 'real', 'double', 'decimal'], true) => (float) ($current ?? $default) + 1.5,
            default => "fuzz-{$name}",
        };
    }
}
