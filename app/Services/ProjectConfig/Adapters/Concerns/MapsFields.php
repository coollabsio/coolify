<?php

namespace App\Services\ProjectConfig\Adapters\Concerns;

use App\Services\ProjectConfig\Field;
use App\Services\ProjectConfig\FieldMap;
use App\Services\ProjectConfig\FieldValues;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * Reads plain managed columns into file paths, and builds the JSON schema of those paths.
 */
trait MapsFields
{
    /**
     * Paths that the adapter reads and writes itself, so the generic loop skips them.
     *
     * @return list<string>
     */
    abstract protected function customPaths(): array;

    /**
     * @param  array<string, Model|null>  $rows  table name => row
     */
    protected function readMappedFields(FieldMap $map, array $rows): array
    {
        $result = [];
        foreach ($map->managed() as $field) {
            if ($field->path === null || in_array($field->path, $this->customPaths(), true)) {
                continue;
            }
            $row = $rows[$field->table ?? $map->table] ?? null;
            $value = FieldValues::toFile($field, $row?->getAttribute($field->column));
            if ($value !== null && ! $this->isDefault($field, $value)) {
                data_set($result, $field->path, $value);
            }
        }

        return $result;
    }

    /**
     * Drops values that equal the default, also in nested groups, and empty groups.
     */
    protected function dropMappedDefaults(FieldMap $map, array $desired): array
    {
        foreach ($map->managed() as $field) {
            if ($field->path === null || in_array($field->path, $this->customPaths(), true) || ! data_has($desired, $field->path)) {
                continue;
            }
            $value = FieldValues::cast($field->jsonType, data_get($desired, $field->path));
            if ($this->isDefault($field, $value)) {
                $this->forgetPath($desired, $field->path);
            } else {
                data_set($desired, $field->path, $value);
            }
        }

        return $desired;
    }

    protected function isDefault(Field $field, mixed $value): bool
    {
        if ($value === null || $value === [] || $value === '') {
            return $field->default === null || $field->default === '' || $field->default === [];
        }

        return FieldValues::cast($field->jsonType, $field->default) === $value;
    }

    protected function forgetPath(array &$data, string $path): void
    {
        Arr::forget($data, $path);
        $segments = explode('.', $path);
        while (count($segments) > 1) {
            array_pop($segments);
            $parent = implode('.', $segments);
            if (data_get($data, $parent) !== []) {
                break;
            }
            Arr::forget($data, $parent);
        }
    }

    /**
     * Nested JSON schema properties for the managed paths.
     */
    protected function mappedFieldsSchema(FieldMap $map): array
    {
        $properties = [];
        foreach ($map->managed() as $field) {
            if ($field->path === null || in_array($field->path, $this->customPaths(), true)) {
                continue;
            }
            $segments = explode('.', $field->path);
            $node = &$properties;
            foreach (array_slice($segments, 0, -1) as $segment) {
                $node[$segment] ??= ['type' => 'object', 'additionalProperties' => false, 'properties' => []];
                $node = &$node[$segment]['properties'];
            }
            $node[end($segments)] = $this->jsonTypeSchema($field);
            unset($node);
        }

        return $properties;
    }

    protected function jsonTypeSchema(Field $field): array
    {
        return match ($field->jsonType) {
            'array' => ['type' => 'array', 'items' => ['type' => 'string']],
            'object' => ['type' => 'object'],
            default => ['type' => [$field->jsonType, 'null']],
        };
    }
}
