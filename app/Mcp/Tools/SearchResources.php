<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class SearchResources extends Tool
{
    protected string $name = 'search_resources';

    protected string $description = 'Fuzzy search across team-owned applications, services, databases, servers, and projects by name, UUID, or domain. Use when the resource type is unknown.';

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

        $query = $request->get('query');
        if (! is_string($query) || trim($query) === '') {
            return $this->mcpError($request, 'query argument is required.');
        }

        $needle = trim($query);
        $like = '%'.strtolower($needle).'%';
        $limit = max(1, min(50, (int) ($request->get('limit') ?? 25)));

        $types = $request->get('types');
        $allowed = ['application', 'service', 'database', 'server', 'project'];
        if ($types !== null) {
            if (! is_string($types) && ! is_array($types)) {
                return $this->mcpError($request, 'types must be a comma-separated string or omitted.');
            }
            $typesList = is_array($types)
                ? $types
                : array_filter(array_map('trim', explode(',', $types)));
            $typesList = array_values(array_intersect($typesList, $allowed));
            if ($typesList === []) {
                return $this->mcpError($request, 'types must include application, service, database, server, and/or project.');
            }
        } else {
            $typesList = $allowed;
        }

        $results = collect();

        if (in_array('project', $typesList, true)) {
            Project::where('team_id', $teamId)
                ->where(function ($q) use ($like, $needle) {
                    $q->whereRaw('LOWER(name) LIKE ?', [$like])
                        ->orWhere('uuid', $needle)
                        ->orWhereRaw('LOWER(COALESCE(description, \'\')) LIKE ?', [$like]);
                })
                ->limit($limit)
                ->get()
                ->each(fn ($p) => $results->push([
                    'type' => 'project',
                    'uuid' => $p->uuid,
                    'name' => $p->name,
                    'match' => 'name_or_uuid',
                ]));
        }

        if (in_array('server', $typesList, true)) {
            Server::whereTeamId($teamId)
                ->where(function ($q) use ($like, $needle) {
                    $q->whereRaw('LOWER(name) LIKE ?', [$like])
                        ->orWhere('uuid', $needle)
                        ->orWhereRaw('LOWER(ip) LIKE ?', [$like]);
                })
                ->limit($limit)
                ->get()
                ->each(fn ($s) => $results->push([
                    'type' => 'server',
                    'uuid' => $s->uuid,
                    'name' => $s->name,
                    'ip' => $s->ip,
                    'match' => 'name_ip_or_uuid',
                ]));
        }

        if (in_array('application', $typesList, true)) {
            Application::ownedByCurrentTeamAPI($teamId)
                ->with(['environment.project:id,uuid,name,team_id'])
                ->where(function ($q) use ($like, $needle) {
                    $q->whereRaw('LOWER(name) LIKE ?', [$like])
                        ->orWhere('uuid', $needle)
                        ->orWhereRaw('LOWER(COALESCE(fqdn, \'\')) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(COALESCE(git_repository, \'\')) LIKE ?', [$like]);
                })
                ->limit($limit)
                ->get()
                ->each(fn ($app) => $results->push([
                    'type' => 'application',
                    'uuid' => $app->uuid,
                    'name' => $app->name,
                    'status' => $app->status,
                    'fqdn' => $app->fqdn,
                    'project_uuid' => $app->environment?->project?->uuid,
                    'project_name' => $app->environment?->project?->name,
                    'match' => 'name_domain_repo_or_uuid',
                ]));
        }

        if (in_array('service', $typesList, true)) {
            Service::whereHas('environment.project', fn ($q) => $q->where('team_id', $teamId))
                ->with(['environment.project:id,uuid,name,team_id'])
                ->where(function ($q) use ($like, $needle) {
                    $q->whereRaw('LOWER(name) LIKE ?', [$like])
                        ->orWhere('uuid', $needle);
                })
                ->limit($limit)
                ->get()
                ->each(fn ($svc) => $results->push([
                    'type' => 'service',
                    'uuid' => $svc->uuid,
                    'name' => $svc->name,
                    'status' => $svc->status ?? null,
                    'project_uuid' => $svc->environment?->project?->uuid,
                    'project_name' => $svc->environment?->project?->name,
                    'match' => 'name_or_uuid',
                ]));
        }

        if (in_array('database', $typesList, true)) {
            $projects = Project::where('team_id', $teamId)->select('id', 'uuid', 'name')->get()->keyBy('id');
            $envToProject = Environment::query()
                ->whereIn('project_id', $projects->keys())
                ->pluck('project_id', 'id');
            $envIds = $envToProject->keys();

            if ($envIds->isNotEmpty()) {
                foreach (STANDALONE_DATABASE_MODELS as $modelClass) {
                    $rows = $modelClass::query()
                        ->whereIn('environment_id', $envIds)
                        ->where(function ($q) use ($like, $needle) {
                            $q->whereRaw('LOWER(name) LIKE ?', [$like])
                                ->orWhere('uuid', $needle);
                        })
                        ->limit($limit)
                        ->get(['uuid', 'name', 'status', 'environment_id']);

                    foreach ($rows as $db) {
                        $projectId = $envToProject[$db->environment_id] ?? null;
                        $project = $projectId ? $projects->get($projectId) : null;
                        $results->push([
                            'type' => method_exists($db, 'type') ? $db->type() : 'database',
                            'resource_kind' => 'database',
                            'uuid' => $db->uuid,
                            'name' => $db->name,
                            'status' => $db->status ?? null,
                            'project_uuid' => $project?->uuid,
                            'project_name' => $project?->name,
                            'match' => 'name_or_uuid',
                        ]);
                    }
                }
            }
        }

        $ranked = $results
            ->sortBy(function ($item) use ($needle) {
                $exact = strcasecmp((string) ($item['uuid'] ?? ''), $needle) === 0
                    || strcasecmp((string) ($item['name'] ?? ''), $needle) === 0;

                return $exact ? 0 : 1;
            })
            ->take($limit)
            ->values()
            ->all();

        return $this->mcpSuccess($request, $this->respond([
            'query' => $needle,
            'results' => $ranked,
            'count' => count($ranked),
        ]));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Search string (name, UUID, domain, IP, git repo).')->required(),
            'types' => $schema->string()->description('Optional comma-separated types: application,service,database,server,project.'),
            'limit' => $schema->integer()->description('Max results (default 25, max 50).'),
        ];
    }
}
