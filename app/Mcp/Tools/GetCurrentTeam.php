<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Team;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class GetCurrentTeam extends Tool
{
    protected string $name = 'get_current_team';

    protected string $description = 'Get the team associated with the authenticated API token.';

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

        // Teams have no public UUID column; expose stable name + flags only.
        // Numeric team ids are intentionally omitted (scrubSensitive policy).
        return $this->mcpSuccess($request, $this->respond($this->scrubSensitive([
            'name' => $team->name,
            'description' => $team->description ?? null,
            'personal_team' => (bool) ($team->personal_team ?? false),
            'member_count' => $team->members()->count(),
            'is_mcp_server_enabled' => (bool) $team->is_mcp_server_enabled,
        ])));
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
