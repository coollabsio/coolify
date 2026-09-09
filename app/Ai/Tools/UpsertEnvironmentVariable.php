<?php

namespace App\Ai\Tools;

use App\Ai\Concerns\AuthorizesToolAction;
use App\Mcp\Concerns\ResolvesResource;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class UpsertEnvironmentVariable implements Approvable, Tool
{
    use AuthorizesToolAction;
    use InteractsWithApprovals;
    use ResolvesResource;

    public function description(): string
    {
        return 'Create or update an environment variable on an application, database, or service in the current team. '
            .'Configuration change; requires admin or owner role and an explicit human approval. Provide resource, uuid, key, and value.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'resource' => $schema->string()->description('application | database | service')->required(),
            'uuid' => $schema->string()->description('Resource UUID.')->required(),
            'key' => $schema->string()->description('Environment variable key.')->required(),
            'value' => $schema->string()->description('Environment variable value.')->required(),
        ];
    }

    protected function needsApproval(Request $request): Approval|bool
    {
        $target = $this->resolve($request);
        if (! $target) {
            return false;
        }

        $this->authorizeToolAction('manageEnvironment', $target, 'upsert_environment_variable');

        $key = (string) ($request->all()['key'] ?? '');
        $name = $target->name ?? $target->uuid;

        return Approval::required("Set environment variable \"{$key}\" on \"{$name}\" ({$target->uuid}).");
    }

    public function handle(Request $request): string
    {
        $args = $request->validate([
            'resource' => 'required|in:application,database,service',
            'uuid' => 'required|string',
            'key' => 'required|string',
            'value' => 'required|string',
        ]);

        $target = $this->resolve($request);
        if (! $target) {
            return "The {$args['resource']} [{$args['uuid']}] was not found in this team.";
        }

        $this->authorizeToolAction('manageEnvironment', $target, 'upsert_environment_variable');

        $target->environment_variables()->updateOrCreate(
            ['key' => $args['key']],
            ['value' => $args['value']],
        );

        $this->auditToolCall('upsert_environment_variable', 'success', [
            'resource' => $args['resource'],
            'resource_uuid' => $args['uuid'],
            'key' => $args['key'],
        ]);

        return "Environment variable \"{$args['key']}\" set on {$args['resource']} [{$args['uuid']}].";
    }

    private function resolve(Request $request): mixed
    {
        $args = $request->all();
        $type = $args['resource'] ?? null;
        $uuid = $args['uuid'] ?? null;
        if (! is_string($type) || ! is_string($uuid) || $uuid === '') {
            return null;
        }
        if (! in_array($type, ['application', 'database', 'service'], true)) {
            return null;
        }

        return $this->resolveTeamResource($this->actingTeamId(), $type, $uuid);
    }
}
