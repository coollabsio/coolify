<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\PrivateKey;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ListPrivateKeys extends Tool
{
    use BuildsResponse;
    use ResolvesTeam;

    protected string $name = 'list_private_keys';

    protected string $description = 'List SSH private keys owned by the authenticated team (uuid, name, description, is_git_related). Key material is never returned. Use a git-related key uuid as private_key_uuid for create_application type private-deploy-key.';

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
        $query = PrivateKey::query()->where('team_id', $teamId)->orderBy('name');
        $total = (clone $query)->count();

        $keys = $query->skip($args['offset'])->take($args['per_page'])->get()
            ->map(fn (PrivateKey $key) => [
                'uuid' => $key->uuid,
                'name' => $key->name,
                'description' => $key->description,
                'is_git_related' => (bool) $key->is_git_related,
            ])->values()->all();

        return $this->mcpSuccess($request, $this->respond($keys, [], $this->paginationMeta('list_private_keys', $args, $total)));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'page' => $schema->integer()->description('Page number (default 1).'),
            'per_page' => $schema->integer()->description('Items per page (default 50, max 100).'),
        ];
    }
}
