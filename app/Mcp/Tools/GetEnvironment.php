<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Environment;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class GetEnvironment extends Tool
{
    protected string $name = 'get_environment';

    protected string $description = 'Get an environment by name or UUID within a team-owned project, with capped resource summaries and counts. Use list_applications / list_services / list_databases with environment_uuid for full inventory.';

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
        $envKey = $request->get('environment_name_or_uuid');

        if (! is_string($projectUuid) || $projectUuid === '') {
            return $this->mcpError($request, 'project_uuid argument is required.');
        }
        if (! is_string($envKey) || $envKey === '') {
            return $this->mcpError($request, 'environment_name_or_uuid argument is required.');
        }

        $project = Project::where('team_id', $teamId)->where('uuid', $projectUuid)->first();
        if (! $project) {
            return $this->mcpError($request, "Project [{$projectUuid}] not found.", ['resource_uuid' => $projectUuid]);
        }

        $environment = Environment::where('project_id', $project->id)
            ->where(function ($q) use ($envKey) {
                $q->where('uuid', $envKey)->orWhere('name', $envKey);
            })
            ->first();

        if (! $environment) {
            return $this->mcpError($request, "Environment [{$envKey}] not found.", ['resource_uuid' => $envKey]);
        }

        $samplePerType = max(1, min($this->maxPerPage, (int) ($request->get('sample_per_type') ?? $this->defaultPerPage)));

        $appQuery = $environment->applications()->orderBy('name')->orderBy('id');
        $appCount = (clone $appQuery)->count();
        $applications = $appQuery
            ->limit($samplePerType)
            ->get()
            ->map(fn ($app) => [
                'uuid' => $app->uuid,
                'name' => $app->name,
                'status' => $app->status,
                'fqdn' => $app->fqdn,
            ])
            ->values()
            ->all();

        $serviceQuery = $environment->services()->orderBy('name')->orderBy('id');
        $serviceCount = (clone $serviceQuery)->count();
        $services = $serviceQuery
            ->limit($samplePerType)
            ->get()
            ->map(fn ($svc) => [
                'uuid' => $svc->uuid,
                'name' => $svc->name,
                'status' => $svc->status ?? null,
            ])
            ->values()
            ->all();

        // databases() returns a merged Collection (polymorphic standalone types), not a builder.
        $allDatabases = $environment->databases()
            ->sortBy(fn ($db) => strtolower((string) ($db->name ?? '')), SORT_NATURAL)
            ->values();
        $databaseCount = $allDatabases->count();
        $databases = $allDatabases
            ->take($samplePerType)
            ->map(fn ($db) => [
                'uuid' => $db->uuid,
                'name' => $db->name,
                'status' => $db->status ?? null,
                'type' => method_exists($db, 'type') ? $db->type() : class_basename($db),
            ])
            ->values()
            ->all();

        $truncated = [
            'applications' => $appCount > $samplePerType,
            'services' => $serviceCount > $samplePerType,
            'databases' => $databaseCount > $samplePerType,
        ];

        $nextTools = [];
        $envUuid = $environment->uuid;
        if ($truncated['applications']) {
            $nextTools[] = [
                'tool' => 'list_applications',
                'args' => ['environment_uuid' => $envUuid],
                'hint' => 'Full application list for this environment',
            ];
        }
        if ($truncated['services']) {
            $nextTools[] = [
                'tool' => 'list_services',
                'args' => ['environment_uuid' => $envUuid],
                'hint' => 'Full service list for this environment',
            ];
        }
        if ($truncated['databases']) {
            $nextTools[] = [
                'tool' => 'list_databases',
                'args' => ['environment_uuid' => $envUuid],
                'hint' => 'Full database list for this environment',
            ];
        }

        $data = [
            'uuid' => $environment->uuid,
            'name' => $environment->name,
            'project' => [
                'uuid' => $project->uuid,
                'name' => $project->name,
            ],
            'sample_per_type' => $samplePerType,
            'counts' => [
                'applications' => $appCount,
                'services' => $serviceCount,
                'databases' => $databaseCount,
            ],
            'truncated' => $truncated,
            'applications' => $applications,
            'services' => $services,
            'databases' => $databases,
            'next_tools' => $nextTools,
        ];

        return $this->mcpSuccess($request, $this->respond($this->scrubSensitive($data)), [
            'resource_uuid' => $environment->uuid,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_uuid' => $schema->string()->description('Project UUID.')->required(),
            'environment_name_or_uuid' => $schema->string()->description('Environment name or UUID.')->required(),
            'sample_per_type' => $schema->integer()->description('Max resources per type in the sample (default 50, max 100). Use list_* tools for full inventory.'),
        ];
    }
}
