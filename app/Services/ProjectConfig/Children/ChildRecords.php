<?php

namespace App\Services\ProjectConfig\Children;

use App\Services\ProjectConfig\ApplyContext;
use App\Services\ProjectConfig\FieldMap;
use Illuminate\Database\Eloquent\Model;

/**
 * One kind of record that belongs to a resource, under one key of the file.
 */
interface ChildRecords
{
    /** The key in the file, for example `env`. */
    public function key(): string;

    /** Field decisions for coverage, or null when the records are team-level (tags). */
    public function map(): ?FieldMap;

    /** File form, or null when the resource has none. */
    public function read(Model $owner): mixed;

    /** The desired value in the same form as read(), or null when empty. */
    public function canonical(mixed $desired): mixed;

    public function schema(): array;

    public function write(Model $owner, mixed $desired, ApplyContext $ctx): void;
}
