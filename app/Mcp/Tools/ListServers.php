<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Server;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ListServers extends Tool
{
    protected string $name = 'list_servers';

    protected string $description = 'List servers visible to the authenticated team token. Optional reachable filter (true/false).';

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

        $reachable = $request->get('reachable');
        $reachableFilter = null;
        if ($reachable !== null) {
            if (is_bool($reachable)) {
                $reachableFilter = $reachable;
            } elseif (is_string($reachable) && in_array(strtolower($reachable), ['true', 'false', '1', '0'], true)) {
                $reachableFilter = in_array(strtolower($reachable), ['true', '1'], true);
            } else {
                return $this->mcpError($request, 'reachable must be true or false.');
            }
        }

        $args = $this->paginationArgs($request);

        $query = Server::whereTeamId($teamId)
            ->with('settings:id,server_id,is_reachable,is_usable')
            ->when($reachableFilter !== null, function ($query) use ($reachableFilter) {
                $query->whereHas('settings', fn ($q) => $q->where('is_reachable', $reachableFilter));
            });

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

        $extra = array_filter([
            'reachable' => $reachableFilter === null ? null : ($reachableFilter ? 'true' : 'false'),
        ], fn ($v) => $v !== null);

        return $this->mcpSuccess($request, $this->respond(
            $summaries,
            [],
            $this->paginationMeta('list_servers', $args, $total, $extra),
        ));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'reachable' => $schema->boolean()->description('Optional filter: only reachable (true) or unreachable (false) servers.'),
            'page' => $schema->integer()->description('Page number (default 1).'),
            'per_page' => $schema->integer()->description('Items per page (default 50, max 100).'),
        ];
    }
}
