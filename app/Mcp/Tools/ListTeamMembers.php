<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Team;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ListTeamMembers extends Tool
{
    protected string $name = 'list_team_members';

    protected string $description = 'List members of the team associated with the authenticated API token.';

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

        $team = Team::query()->find($teamId);
        if (! $team) {
            return $this->mcpError($request, 'Team not found.');
        }

        $args = $this->paginationArgs($request);
        $members = $team->members()->orderBy('name')->get();
        $total = $members->count();

        // Users have no public UUID column; email is the stable public identifier.
        $page = $members
            ->slice($args['offset'], $args['per_page'])
            ->map(fn ($user) => $this->scrubSensitive([
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->pivot->role ?? null,
            ]))
            ->values()
            ->all();

        return $this->mcpSuccess($request, $this->respond(
            $page,
            [],
            $this->paginationMeta('list_team_members', $args, $total),
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
