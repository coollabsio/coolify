<?php

namespace App\Ai\Tools;

use App\Ai\Concerns\AuthorizesToolAction;
use App\Jobs\DeleteResourceJob;
use App\Mcp\Concerns\ResolvesResource;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class DeleteResource implements Approvable, Tool
{
    use AuthorizesToolAction;
    use InteractsWithApprovals;
    use ResolvesResource;

    public function description(): string
    {
        return 'Permanently delete an application, database, or service in the current team. Destructive and '
            .'irreversible; requires admin or owner role and an explicit human approval. Provide resource (application|database|service) and uuid.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'resource' => $schema->string()->description('application | database | service')->required(),
            'uuid' => $schema->string()->description('Resource UUID.')->required(),
        ];
    }

    protected function needsApproval(Request $request): Approval|bool
    {
        $target = $this->resolve($request);
        if (! $target) {
            return false;
        }

        $this->authorizeToolAction('delete', $target, 'delete_resource');

        $type = (string) ($request->all()['resource'] ?? 'resource');
        $name = $target->name ?? $target->uuid;

        return Approval::required("Delete {$type} \"{$name}\" ({$target->uuid}). This is permanent and cannot be undone.");
    }

    public function handle(Request $request): string
    {
        $args = $request->validate([
            'resource' => 'required|in:application,database,service',
            'uuid' => 'required|string',
        ]);

        $target = $this->resolve($request);
        if (! $target) {
            return "The {$args['resource']} [{$args['uuid']}] was not found in this team.";
        }

        $this->authorizeToolAction('delete', $target, 'delete_resource');

        DeleteResourceJob::dispatch($target);

        $this->auditToolCall('delete_resource', 'success', [
            'resource' => $args['resource'],
            'resource_uuid' => $args['uuid'],
        ]);

        return "Deletion of {$args['resource']} [{$args['uuid']}] queued.";
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
