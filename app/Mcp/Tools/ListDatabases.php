<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ListDatabases extends Tool
{
    protected string $name = 'list_databases';

    protected string $description = 'List standalone databases owned by the authenticated team. Filters: project_uuid, environment_uuid, server_uuid, status, name.';

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

        $projectUuid = $request->get('project_uuid');
        if ($projectUuid !== null && (! is_string($projectUuid) || $projectUuid === '')) {
            return $this->mcpError($request, 'project_uuid must be a non-empty string.');
        }

        $environmentUuid = $request->get('environment_uuid');
        if ($environmentUuid !== null && (! is_string($environmentUuid) || $environmentUuid === '')) {
            return $this->mcpError($request, 'environment_uuid must be a non-empty string.');
        }

        $serverUuid = $request->get('server_uuid');
        if ($serverUuid !== null && (! is_string($serverUuid) || $serverUuid === '')) {
            return $this->mcpError($request, 'server_uuid must be a non-empty string.');
        }

        $status = $request->get('status');
        if ($status !== null && (! is_string($status) || trim($status) === '')) {
            return $this->mcpError($request, 'status must be a non-empty string.');
        }

        $name = $request->get('name');
        if ($name !== null && (! is_string($name) || trim($name) === '')) {
            return $this->mcpError($request, 'name argument must be a non-empty string.');
        }

        $args = $this->paginationArgs($request);
        $extra = array_filter([
            'project_uuid' => $projectUuid,
            'environment_uuid' => $environmentUuid,
            'server_uuid' => $serverUuid,
            'status' => $status,
            'name' => $name,
        ], fn ($v) => $v !== null);

        $projectsQuery = Project::where('team_id', $teamId)->select('id', 'uuid', 'name');
        if (is_string($projectUuid)) {
            $projectsQuery->where('uuid', $projectUuid);
        }
        $projects = $projectsQuery->get()->keyBy('id');

        if ($projects->isEmpty()) {
            return $this->mcpSuccess($request, $this->respond(
                [],
                [],
                $this->paginationMeta('list_databases', $args, 0, $extra),
            ));
        }

        $envQuery = Environment::query()->whereIn('project_id', $projects->keys());
        if (is_string($environmentUuid)) {
            $env = Environment::ownedByCurrentTeamAPI($teamId)->where('uuid', $environmentUuid)->first();
            if (! $env || ! $projects->has($env->project_id)) {
                return $this->mcpSuccess($request, $this->respond(
                    [],
                    [],
                    $this->paginationMeta('list_databases', $args, 0, $extra),
                ));
            }
            $envQuery->where('id', $env->id);
        }

        $envIds = $envQuery->pluck('id');
        if ($envIds->isEmpty()) {
            return $this->mcpSuccess($request, $this->respond(
                [],
                [],
                $this->paginationMeta('list_databases', $args, 0, $extra),
            ));
        }

        $destinationFilter = null;
        if (is_string($serverUuid)) {
            $server = Server::whereTeamId($teamId)->where('uuid', $serverUuid)->first();
            if (! $server) {
                return $this->mcpSuccess($request, $this->respond(
                    [],
                    [],
                    $this->paginationMeta('list_databases', $args, 0, $extra),
                ));
            }
            // Keep morph type and ID sets separate so overlapping auto-increment IDs
            // across standalone_dockers / swarm_dockers cannot cross-match.
            $destinationFilter = [
                StandaloneDocker::class => $server->standaloneDockers()->pluck('id')->all(),
                SwarmDocker::class => $server->swarmDockers()->pluck('id')->all(),
            ];
        }

        $union = $this->buildDatabaseUnion(
            $envIds->all(),
            $destinationFilter,
            is_string($name) ? $name : null,
            is_string($status) ? $status : null,
        );

        if ($union === null) {
            return $this->mcpSuccess($request, $this->respond(
                [],
                [],
                $this->paginationMeta('list_databases', $args, 0, $extra),
            ));
        }

        $total = (int) DB::query()->fromSub($union, 'databases')->count();

        $summaries = DB::query()
            ->fromSub($union, 'databases')
            ->orderBy('name')
            ->orderBy('uuid')
            ->offset($args['offset'])
            ->limit($args['per_page'])
            ->get()
            ->map(fn ($row) => [
                'uuid' => $row->uuid,
                'name' => $row->name,
                'status' => $row->status,
                'type' => $row->type,
                'project_uuid' => $row->project_uuid,
                'project_name' => $row->project_name,
            ])
            ->values()
            ->all();

        return $this->mcpSuccess($request, $this->respond(
            $summaries,
            [],
            $this->paginationMeta('list_databases', $args, $total, $extra),
        ));
    }

    /**
     * @param  list<int|string>  $envIds
     * @param  array<class-string, list<int|string>>|null  $destinationFilter  morph class => destination IDs
     */
    private function buildDatabaseUnion(
        array $envIds,
        ?array $destinationFilter,
        ?string $name,
        ?string $status,
    ): ?\Illuminate\Database\Query\Builder {
        $parts = [];

        foreach (STANDALONE_DATABASE_MODELS as $typeKey => $modelClass) {
            $parts[] = $this->databaseSelectQuery(
                $modelClass,
                (string) $typeKey,
                $envIds,
                $destinationFilter,
                $name,
                $status,
            );
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

    /**
     * @param  class-string  $modelClass
     * @param  list<int|string>  $envIds
     * @param  array<class-string, list<int|string>>|null  $destinationFilter  morph class => destination IDs
     */
    private function databaseSelectQuery(
        string $modelClass,
        string $typeKey,
        array $envIds,
        ?array $destinationFilter,
        ?string $name,
        ?string $status,
    ): Builder {
        /** @var Model $model */
        $model = new $modelClass;
        $table = $model->getTable();
        $resourceType = method_exists($model, 'type') ? $model->type() : 'standalone-'.$typeKey;
        $typeLiteral = "'".str_replace("'", "''", $resourceType)."'";

        return $modelClass::query()
            ->select([
                "{$table}.uuid",
                "{$table}.name",
                "{$table}.status",
                DB::raw("{$typeLiteral} as type"),
                'projects.uuid as project_uuid',
                'projects.name as project_name',
            ])
            ->join('environments', "{$table}.environment_id", '=', 'environments.id')
            ->join('projects', 'environments.project_id', '=', 'projects.id')
            ->whereIn("{$table}.environment_id", $envIds)
            ->when(is_array($destinationFilter), function ($q) use ($table, $destinationFilter) {
                $q->where(function ($outer) use ($table, $destinationFilter) {
                    $hasPredicate = false;
                    foreach ($destinationFilter as $destinationType => $ids) {
                        if ($ids === []) {
                            continue;
                        }
                        $hasPredicate = true;
                        $outer->orWhere(function ($inner) use ($table, $destinationType, $ids) {
                            $inner->where("{$table}.destination_type", $destinationType)
                                ->whereIn("{$table}.destination_id", $ids);
                        });
                    }
                    if (! $hasPredicate) {
                        $outer->whereRaw('1 = 0');
                    }
                });
            })
            ->when(is_string($name), fn ($q) => $q->whereRaw("LOWER({$table}.name) LIKE ?", ['%'.strtolower($name).'%']))
            ->when(is_string($status), fn ($q) => $q->whereRaw("LOWER({$table}.status) LIKE ?", ['%'.strtolower($status).'%']));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_uuid' => $schema->string()->description('Optional project UUID filter.'),
            'environment_uuid' => $schema->string()->description('Optional environment UUID filter.'),
            'server_uuid' => $schema->string()->description('Optional server UUID filter.'),
            'status' => $schema->string()->description('Optional status substring filter.'),
            'name' => $schema->string()->description('Optional name substring filter.'),
            'page' => $schema->integer()->description('Page number (default 1).'),
            'per_page' => $schema->integer()->description('Items per page (default 50, max 100).'),
        ];
    }
}
