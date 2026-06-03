<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Server;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('list_servers')]
#[Description('List servers visible to the authenticated team token. Returns summary (uuid, name, ip, reachability). Use get_server for full details.')]
class ListServers extends Tool
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

        $query = Server::whereTeamId($teamId)->with('settings:id,server_id,is_reachable,is_usable');
        $total = (clone $query)->count();

        $summaries = $query
            ->orderBy('name')
            ->skip($args['offset'])
            ->take($args['per_page'])
            ->get()
            ->map(fn ($s) => [
                'uuid' => $s->uuid,
                'name' => $s->name,
                'ip' => $s->ip,
                'is_reachable' => $s->settings?->is_reachable,
                'is_usable' => $s->settings?->is_usable,
            ])
            ->values()
            ->all();

        return $this->respond(
            $summaries,
            [],
            $this->paginationMeta('list_servers', $args, $total),
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
