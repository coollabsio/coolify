<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Application;
use App\Models\Service;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ListResources extends Tool
{
    protected string $name = 'list_resources';

    protected string $description = 'List all resources (applications, services, databases) owned by the authenticated team. Optional filters: type, project_uuid, tag.';

    use BuildsResponse;
    use ResolvesTeam;

    public function handle(Request $request): Response
    {
        if ($error = $this->ensureAbility($request, 'read', $this->name)) {
            return $error;
        }

        $teamId = $this->resolveTeamId($request);
        if (is_null($teamId)) {
            return $this->mcpError($request, 'Invalid token.');
        }

        $typeFilter = $request->get('type');
        if ($typeFilter !== null && (! is_string($typeFilter) || ! in_array($typeFilter, ['application', 'service', 'database'], true))) {
            return $this->mcpError($request, 'type must be one of: application, service, database.');
        }

        $projectUuid = $request->get('project_uuid');
        if ($projectUuid !== null && (! is_string($projectUuid) || $projectUuid === '')) {
            return $this->mcpError($request, 'project_uuid must be a non-empty string.');
        }

        $tagName = $request->get('tag');
        if ($tagName !== null && (! is_string($tagName) || trim($tagName) === '')) {
            return $this->mcpError($request, 'tag argument must be a non-empty string.');
        }

        $args = $this->paginationArgs($request);

        $union = $this->buildResourceUnion(
            $teamId,
            is_string($typeFilter) ? $typeFilter : null,
            is_string($projectUuid) ? $projectUuid : null,
            is_string($tagName) ? $tagName : null,
        );

        $extra = array_filter([
            'type' => $typeFilter,
            'project_uuid' => $projectUuid,
            'tag' => $tagName,
        ], fn ($v) => $v !== null);

        if ($union === null) {
            return $this->mcpSuccess($request, $this->respond(
                [],
                [],
                $this->paginationMeta('list_resources', $args, 0, $extra),
            ));
        }

        $total = (int) DB::query()->fromSub($union, 'resources')->count();

        $rows = DB::query()
            ->fromSub($union, 'resources')
            ->orderBy('name')
            ->offset($args['offset'])
            ->limit($args['per_page'])
            ->get();

        $page = $this->mapPageRows($rows);

        return $this->mcpSuccess($request, $this->respond(
            $page,
            [],
            $this->paginationMeta('list_resources', $args, $total, $extra),
        ));
    }

    /**
     * Build a UNION ALL of team-scoped resource queries (apps, services, DBs).
     * Sorting and pagination are applied by the caller on the outer query so only
     * one page of rows is materialised.
     */
    private function buildResourceUnion(
        int $teamId,
        ?string $typeFilter,
        ?string $projectUuid,
        ?string $tagName,
    ): ?QueryBuilder {
        $parts = [];

        if ($typeFilter === null || $typeFilter === 'application') {
            $parts[] = $this->applicationQuery($teamId, $projectUuid, $tagName);
        }

        if ($typeFilter === null || $typeFilter === 'service') {
            $parts[] = $this->serviceQuery($teamId, $projectUuid, $tagName);
        }

        if ($typeFilter === null || $typeFilter === 'database') {
            foreach (STANDALONE_DATABASE_MODELS as $typeKey => $modelClass) {
                $parts[] = $this->databaseQuery($modelClass, (string) $typeKey, $teamId, $projectUuid, $tagName);
            }
        }

        if ($parts === []) {
            return null;
        }

        /** @var Builder $union */
        $union = array_shift($parts);
        foreach ($parts as $part) {
            $union->unionAll($part);
        }

        return $union->toBase();
    }

    private function applicationQuery(int $teamId, ?string $projectUuid, ?string $tagName): Builder
    {
        // Drop withCount global scope so UNION column counts match other resource selects.
        $query = Application::query()
            ->withoutGlobalScope('withRelations')
            ->select([
                'applications.uuid',
                'applications.name',
                DB::raw("'application' as type"),
                'applications.status',
                'projects.uuid as project_uuid',
                'projects.name as project_name',
            ])
            ->join('environments', 'applications.environment_id', '=', 'environments.id')
            ->join('projects', 'environments.project_id', '=', 'projects.id')
            ->where('projects.team_id', $teamId);

        $this->applyProjectAndTagFilters($query, 'applications', Application::class, $projectUuid, $tagName);

        return $query;
    }

    private function serviceQuery(int $teamId, ?string $projectUuid, ?string $tagName): Builder
    {
        // Service status is a computed accessor, not a column — fill per page later.
        $query = Service::query()
            ->select([
                'services.uuid',
                'services.name',
                DB::raw("'service' as type"),
                DB::raw('NULL as status'),
                'projects.uuid as project_uuid',
                'projects.name as project_name',
            ])
            ->join('environments', 'services.environment_id', '=', 'environments.id')
            ->join('projects', 'environments.project_id', '=', 'projects.id')
            ->where('projects.team_id', $teamId);

        $this->applyProjectAndTagFilters($query, 'services', Service::class, $projectUuid, $tagName);

        return $query;
    }

    /**
     * @param  class-string  $modelClass
     */
    private function databaseQuery(
        string $modelClass,
        string $typeKey,
        int $teamId,
        ?string $projectUuid,
        ?string $tagName,
    ): Builder {
        /** @var Model $model */
        $model = new $modelClass;
        $table = $model->getTable();
        $resourceType = method_exists($model, 'type') ? $model->type() : 'standalone-'.$typeKey;

        // Type string is model-controlled (e.g. standalone-postgresql), not user input.
        $typeLiteral = "'".str_replace("'", "''", $resourceType)."'";

        $query = $modelClass::query()
            ->select([
                "{$table}.uuid",
                "{$table}.name",
                DB::raw("{$typeLiteral} as type"),
                "{$table}.status",
                'projects.uuid as project_uuid',
                'projects.name as project_name',
            ])
            ->join('environments', "{$table}.environment_id", '=', 'environments.id')
            ->join('projects', 'environments.project_id', '=', 'projects.id')
            ->where('projects.team_id', $teamId);

        $this->applyProjectAndTagFilters($query, $table, $modelClass, $projectUuid, $tagName);

        return $query;
    }

    /**
     * @param  class-string  $modelClass
     */
    private function applyProjectAndTagFilters(
        Builder $query,
        string $table,
        string $modelClass,
        ?string $projectUuid,
        ?string $tagName,
    ): void {
        if (is_string($projectUuid)) {
            $query->where('projects.uuid', $projectUuid);
        }

        if (is_string($tagName)) {
            $morphClass = (new $modelClass)->getMorphClass();
            $query->whereExists(function (QueryBuilder $sub) use ($table, $morphClass, $tagName) {
                $sub->select(DB::raw(1))
                    ->from('taggables')
                    ->join('tags', 'tags.id', '=', 'taggables.tag_id')
                    ->whereColumn('taggables.taggable_id', "{$table}.id")
                    ->where('taggables.taggable_type', $morphClass)
                    ->where('tags.name', $tagName);
            });
        }
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return list<array<string, mixed>>
     */
    private function mapPageRows(Collection $rows): array
    {
        $items = $rows->map(fn ($row) => [
            'uuid' => $row->uuid,
            'name' => $row->name,
            'type' => $row->type,
            'status' => $row->status,
            'project_uuid' => $row->project_uuid,
            'project_name' => $row->project_name,
        ])->values();

        $serviceUuids = $items->where('type', 'service')->pluck('uuid')->filter()->values();
        if ($serviceUuids->isNotEmpty()) {
            $services = Service::query()
                ->whereIn('uuid', $serviceUuids->all())
                ->with([
                    'applications:id,service_id,status,exclude_from_status',
                    'databases:id,service_id,status,exclude_from_status',
                ])
                ->get()
                ->keyBy('uuid');

            $items = $items->map(function (array $item) use ($services) {
                if ($item['type'] === 'service') {
                    $item['status'] = $services->get($item['uuid'])?->status;
                }

                return $item;
            });
        }

        return $items->all();
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->description('Optional filter: application, service, or database.'),
            'project_uuid' => $schema->string()->description('Optional project UUID filter.'),
            'tag' => $schema->string()->description('Optional tag name filter.'),
            'page' => $schema->integer()->description('Page number (default 1).'),
            'per_page' => $schema->integer()->description('Items per page (default 50, max 100).'),
        ];
    }
}
