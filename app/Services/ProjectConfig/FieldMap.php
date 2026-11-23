<?php

namespace App\Services\ProjectConfig;

/**
 * The field decisions for one resource type or child record.
 */
final class FieldMap
{
    public const ALWAYS_IGNORED = ['id', 'uuid', 'created_at', 'updated_at', 'deleted_at', 'config_key'];

    /**
     * @param  list<Field>  $fields
     * @param  array<string, list<string>>  $ownerColumns  Foreign keys to the owner, per table.
     */
    public function __construct(
        public readonly string $table,
        private readonly array $fields,
        private readonly array $ownerColumns = [],
    ) {}

    /**
     * @return list<Field>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    /**
     * @return list<Field>
     */
    public function managed(): array
    {
        return array_values(array_filter($this->fields, fn (Field $field) => $field->class === FieldClass::Managed));
    }

    public function forPath(string $path): ?Field
    {
        foreach ($this->fields as $field) {
            if ($field->path === $path) {
                return $field;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function tables(): array
    {
        $tables = [$this->table];
        foreach ($this->fields as $field) {
            if ($field->table !== null && ! in_array($field->table, $tables, true)) {
                $tables[] = $field->table;
            }
        }

        return $tables;
    }

    public function decides(string $table, string $column): bool
    {
        if (in_array($column, self::ALWAYS_IGNORED, true) || in_array($column, $this->ownerColumns[$table] ?? [], true)) {
            return true;
        }
        foreach ($this->fields as $field) {
            if ($field->column === $column && ($field->table ?? $this->table) === $table) {
                return true;
            }
        }

        return false;
    }
}
