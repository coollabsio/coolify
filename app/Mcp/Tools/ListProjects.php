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

#[Name('list_projects')]
#[Description('List projects owned by the authenticated team. Returns summary (uuid, name, description).')]
class ListProjects extends Tool
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

        $query = Project::whereTeamId($teamId);
        $total = (clone $query)->count();

        $summaries = $query
            ->select('name', 'description', 'uuid')
            ->orderBy('name')
            ->skip($args['offset'])
            ->take($args['per_page'])
            ->get()
            ->map(fn ($p) => [
                'uuid' => $p->uuid,
                'name' => $p->name,
                'description' => $p->description,
            ])
            ->values()
            ->all();

        return $this->respond(
            $summaries,
            [],
            $this->paginationMeta('list_projects', $args, $total),
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
