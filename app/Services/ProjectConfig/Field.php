<?php

namespace App\Services\ProjectConfig;

/**
 * The decision for one stored column: whether the file manages it, and where it lives in the file.
 */
final readonly class Field
{
    /**
     * @param  string|null  $path  Dot path in the file, for example `build.pack`.
     * @param  string|null  $table  Set for columns of a 1:1 table, for example `application_settings`.
     * @param  string  $jsonType  JSON schema type of the file value.
     */
    public function __construct(
        public string $column,
        public FieldClass $class,
        public ?string $path = null,
        public mixed $default = null,
        public ?string $reason = null,
        public ?string $table = null,
        public string $jsonType = 'string',
        public ?string $format = null,
    ) {}

    public static function managed(string $column, string $path, mixed $default = null, string $jsonType = 'string', ?string $table = null, ?string $format = null): self
    {
        return new self($column, FieldClass::Managed, $path, $default, null, $table, $jsonType, $format);
    }

    public static function secret(string $column, string $reason, ?string $table = null): self
    {
        return new self($column, FieldClass::Secret, reason: $reason, table: $table);
    }

    public static function derived(string $column, string $reason, ?string $table = null): self
    {
        return new self($column, FieldClass::Derived, reason: $reason, table: $table);
    }

    public static function state(string $column, string $reason, ?string $table = null): self
    {
        return new self($column, FieldClass::State, reason: $reason, table: $table);
    }
}
