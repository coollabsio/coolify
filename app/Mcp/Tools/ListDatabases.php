<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('list_databases')]
#[Description('List standalone databases owned by the authenticated team. Returns summary (uuid, name, status, type). Use get_database for full details.')]
class ListDatabases extends Tool
{
    use BuildsResponse;
    use ResolvesTeam;

    public function handle(Request $request): Response
    {
        if ($error = $this->ensureAbility($request, 'read')) {
            return $error;
        }

        $teamId = $this->resolveTeamId($request);
        if (is_null($teamId)) {
            return Response::error('Invalid token.');
        }

        $args = $this->paginationArgs($request);

        $projects = Project::where('team_id', $teamId)->get();
        $databases = collect();
        foreach ($projects as $project) {
            $databases = $databases->merge($project->databases());
        }

        $total = $databases->count();

        $summaries = $databases
            ->sortBy('name')
            ->slice($args['offset'], $args['per_page'])
            ->map(fn ($db) => [
                'uuid' => $db->uuid,
                'name' => $db->name,
                'status' => $db->status ?? null,
                'type' => method_exists($db, 'type') ? $db->type() : class_basename($db),
            ])
            ->values()
            ->all();

        return $this->respond(
            $summaries,
            [],
            $this->paginationMeta('list_databases', $args, $total),
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'page' => $schema->integer()->description('Page number (default 1).'),
            'per_page' => $schema->integer()->description('Items per page (default 50, max 100).'),
        ];
    }
}
