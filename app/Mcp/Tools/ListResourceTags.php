<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesResource;
use App\Mcp\Concerns\ResolvesTeam;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ListResourceTags extends Tool
{
    protected string $name = 'list_resource_tags';

    protected string $description = 'List tags attached to an application, database, or service owned by the authenticated team.';

    use BuildsResponse;
    use ResolvesResource;
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

        $resourceType = $request->get('resource');
        $uuid = $request->get('uuid');

        if (! is_string($resourceType) || ! $this->isValidResourceType($resourceType, $this->primaryResourceTypes)) {
            return $this->mcpError($request, 'resource must be one of: application, database, service.');
        }
        if (! is_string($uuid) || $uuid === '') {
            return $this->mcpError($request, 'uuid argument is required.');
        }

        $resource = $this->resolveTeamResource($teamId, $resourceType, $uuid);
        if (! $resource) {
            return $this->mcpError($request, ucfirst($resourceType)." [{$uuid}] not found.", ['resource_uuid' => $uuid]);
        }

        $tags = collect();
        if (method_exists($resource, 'tags')) {
            $tags = $resource->tags
                ->filter(fn ($tag) => (int) $tag->team_id === $teamId || $tag->team_id === null)
                ->map(fn ($tag) => [
                    'uuid' => $tag->uuid,
                    'name' => $tag->name,
                ])
                ->values();
        }

        return $this->mcpSuccess($request, $this->respond([
            'resource' => $resourceType,
            'uuid' => $uuid,
            'tags' => $tags->all(),
        ]), ['resource_uuid' => $uuid]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'resource' => $schema->string()->description('application | database | service')->required(),
            'uuid' => $schema->string()->description('Resource UUID.')->required(),
        ];
    }
}
