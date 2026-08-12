<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesResource;
use App\Mcp\Concerns\ResolvesTeam;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ListEnvKeys extends Tool
{
    protected string $name = 'list_env_keys';

    protected string $description = 'List environment variable key names (never values) for an application, database, or service owned by the authenticated team.';

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

        $envs = collect();
        if (method_exists($resource, 'environment_variables')) {
            $envs = $envs->merge($resource->environment_variables);
        }
        if (method_exists($resource, 'environment_variables_preview')) {
            $envs = $envs->merge($resource->environment_variables_preview);
        }

        $keys = $envs
            ->unique(fn ($env) => $env->uuid ?? ($env->key.'|'.((int) $env->is_preview)))
            ->map(fn ($env) => [
                'uuid' => $env->uuid,
                'key' => $env->key,
                'is_preview' => (bool) $env->is_preview,
                'is_literal' => (bool) ($env->is_literal ?? false),
                'is_multiline' => (bool) ($env->is_multiline ?? false),
                'is_runtime' => (bool) ($env->is_runtime ?? true),
                'is_buildtime' => (bool) ($env->is_buildtime ?? true),
                'is_shown_once' => (bool) ($env->is_shown_once ?? false),
                'comment' => $env->comment,
            ])
            ->values()
            ->all();

        return $this->mcpSuccess($request, $this->respond([
            'resource' => $resourceType,
            'uuid' => $uuid,
            'keys' => $keys,
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
