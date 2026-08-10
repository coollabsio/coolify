<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Tag;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ListTags extends Tool
{
    protected string $name = 'list_tags';

    protected string $description = 'List tags owned by the authenticated team.';

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

        $args = $this->paginationArgs($request);
        $query = Tag::where('team_id', $teamId)->orderBy('name');
        $total = (clone $query)->count();

        $tags = $query
            ->skip($args['offset'])
            ->take($args['per_page'])
            ->get()
            ->map(fn ($tag) => [
                'uuid' => $tag->uuid,
                'name' => $tag->name,
            ])
            ->values()
            ->all();

        return $this->mcpSuccess($request, $this->respond(
            $tags,
            [],
            $this->paginationMeta('list_tags', $args, $total),
        ));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'page' => $schema->integer()->description('Page number (default 1).'),
            'per_page' => $schema->integer()->description('Items per page (default 50, max 100).'),
        ];
    }
}
