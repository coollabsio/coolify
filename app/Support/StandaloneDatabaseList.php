<?php

namespace App\Support;

use App\Models\Environment;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lists the standalone databases of a team across all database tables (API and MCP listings).
 */
class StandaloneDatabaseList
{
    /**
     * One row per database (id, model_key, uuid, name, status, type, project_uuid, project_name), ordered by name and uuid.
     * A project, environment, or server UUID that the team does not own matches no database.
     * Status and name are case-insensitive substring matches.
     *
     * @param  array{project_uuid?: ?string, environment_uuid?: ?string, server_uuid?: ?string, status?: ?string, name?: ?string}  $filters
     */
    public static function query(int $teamId, array $filters = []): QueryBuilder
    {
        $environmentUuid = $filters['environment_uuid'] ?? null;
        $serverUuid = $filters['server_uuid'] ?? null;

        $environmentIds = Environment::ownedByCurrentTeamAPI($teamId)
            ->when(($filters['project_uuid'] ?? null) !== null, fn (Builder $query) => $query->whereRelation('project', 'uuid', $filters['project_uuid']))
            ->when($environmentUuid !== null, fn (Builder $query) => $query->where('uuid', $environmentUuid))
            ->pluck('id')
            ->all();

        $destinationFilter = null;
        if ($serverUuid !== null) {
            $server = Server::whereTeamId($teamId)->where('uuid', $serverUuid)->first();
            // Keep morph type and ID sets separate so overlapping auto-increment IDs
            // across standalone_dockers / swarm_dockers cannot cross-match.
            $destinationFilter = [
                StandaloneDocker::class => $server?->standaloneDockers()->pluck('id')->all() ?? [],
                SwarmDocker::class => $server?->swarmDockers()->pluck('id')->all() ?? [],
            ];
        }

        $parts = [];
        foreach (STANDALONE_DATABASE_MODELS as $modelKey => $modelClass) {
            $parts[] = self::tableQuery($modelClass, (string) $modelKey, $environmentIds, $destinationFilter, $filters['name'] ?? null, $filters['status'] ?? null);
        }

        $union = array_shift($parts);
        foreach ($parts as $part) {
            $union->unionAll($part);
        }

        return DB::query()->fromSub($union->toBase(), 'databases')->orderBy('name')->orderBy('uuid');
    }

    /**
     * Load the database models of the given rows, in the order of the rows.
     *
     * @param  iterable<object{id: int, model_key: string}>  $rows
     * @return Collection<int, Model>
     */
    public static function models(iterable $rows, array $with = []): Collection
    {
        $rows = collect($rows);
        $models = [];
        foreach ($rows->groupBy('model_key') as $modelKey => $group) {
            $modelClass = STANDALONE_DATABASE_MODELS[$modelKey];
            foreach ($modelClass::query()->with($with)->whereIn('id', $group->pluck('id'))->get() as $model) {
                $models[$modelKey.':'.$model->getKey()] = $model;
            }
        }

        return $rows->map(fn ($row) => $models[$row->model_key.':'.$row->id] ?? null)->filter()->values();
    }

    private static function tableQuery(
        string $modelClass,
        string $modelKey,
        array $environmentIds,
        ?array $destinationFilter,
        ?string $name,
        ?string $status,
    ): Builder {
        /** @var Model $model */
        $model = new $modelClass;
        $table = $model->getTable();
        $resourceType = method_exists($model, 'type') ? $model->type() : 'standalone-'.$modelKey;

        return $modelClass::query()
            ->select([
                "{$table}.id",
                DB::raw(DB::getPdo()->quote($modelKey).' as model_key'),
                "{$table}.uuid",
                "{$table}.name",
                "{$table}.status",
                DB::raw(DB::getPdo()->quote($resourceType).' as type'),
                'projects.uuid as project_uuid',
                'projects.name as project_name',
            ])
            ->join('environments', "{$table}.environment_id", '=', 'environments.id')
            ->join('projects', 'environments.project_id', '=', 'projects.id')
            ->whereIn("{$table}.environment_id", $environmentIds)
            ->when(is_array($destinationFilter), function (Builder $query) use ($table, $destinationFilter) {
                $query->where(function (Builder $outer) use ($table, $destinationFilter) {
                    $hasPredicate = false;
                    foreach ($destinationFilter as $destinationType => $ids) {
                        if ($ids === []) {
                            continue;
                        }
                        $hasPredicate = true;
                        $outer->orWhere(fn (Builder $inner) => $inner->where("{$table}.destination_type", $destinationType)->whereIn("{$table}.destination_id", $ids));
                    }
                    if (! $hasPredicate) {
                        $outer->whereRaw('1 = 0');
                    }
                });
            })
            ->when($name !== null, fn (Builder $query) => $query->whereRaw("LOWER({$table}.name) LIKE ?", ['%'.strtolower($name).'%']))
            ->when($status !== null, fn (Builder $query) => $query->whereRaw("LOWER({$table}.status) LIKE ?", ['%'.strtolower($status).'%']));
    }
}
