<?php

namespace App\Services\ProjectConfig\Children;

use App\Models\Tag;
use App\Services\ProjectConfig\ApplyContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Tag names. Tags are team records, so the coverage test does not own their table.
 */
class Tags implements ChildRecords
{
    public function key(): string
    {
        return 'tags';
    }

    public function map(): null
    {
        return null;
    }

    public function read(Model $owner): ?array
    {
        $names = $owner->tags()->pluck('name')->map(fn ($name) => (string) $name)->sort()->values()->all();

        return $names === [] ? null : $names;
    }

    public function canonical(mixed $desired): ?array
    {
        if (! is_array($desired) || $desired === []) {
            return null;
        }
        $names = array_values(array_unique(array_map('strval', $desired)));
        sort($names);

        return $names;
    }

    public function schema(): array
    {
        return ['type' => 'array', 'items' => ['type' => 'string']];
    }

    public function write(Model $owner, mixed $desired, ApplyContext $ctx): void
    {
        $ids = [];
        foreach ((array) ($desired ?? []) as $name) {
            $ids[] = Tag::query()->firstOrCreate(['name' => (string) $name, 'team_id' => $ctx->teamId])->id;
        }
        $owner->tags()->sync($ids);
    }
}
