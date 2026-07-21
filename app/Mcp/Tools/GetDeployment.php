<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\ApplicationDeploymentQueue;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class GetDeployment extends Tool
{
    protected string $name = 'get_deployment';

    protected string $description = 'Get deployment details by deployment UUID for the authenticated team. Build logs and configuration snapshots are never returned.';

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

        $uuid = $request->get('uuid');
        if (! is_string($uuid) || $uuid === '') {
            return $this->mcpError($request, 'uuid argument is required.');
        }

        $deployment = ApplicationDeploymentQueue::where('deployment_uuid', $uuid)->first();
        if (! $deployment) {
            return $this->mcpError($request, "Deployment [{$uuid}] not found.", ['resource_uuid' => $uuid]);
        }

        $application = $deployment->application;
        $appTeamId = $application?->team()?->id;
        if (! $application || (int) $appTeamId !== $teamId) {
            return $this->mcpError($request, "Deployment [{$uuid}] not found.", ['resource_uuid' => $uuid]);
        }

        $data = $this->scrubSensitive([
            'deployment_uuid' => $deployment->deployment_uuid,
            'application_uuid' => $application->uuid,
            'application_name' => $deployment->application_name,
            'server_name' => $deployment->server_name,
            'status' => $deployment->status,
            'commit' => $deployment->commit,
            'commit_message' => $deployment->commit_message,
            'pull_request_id' => $deployment->pull_request_id,
            'force_rebuild' => $deployment->force_rebuild,
            'is_webhook' => $deployment->is_webhook,
            'is_api' => $deployment->is_api,
            'restart_only' => $deployment->restart_only,
            'rollback' => $deployment->rollback,
            'git_type' => $deployment->git_type,
            'deployment_url' => $deployment->deployment_url,
            'docker_registry_image_tag' => $deployment->docker_registry_image_tag,
            'created_at' => $deployment->created_at,
            'updated_at' => $deployment->updated_at,
            'finished_at' => $deployment->finished_at,
        ]);

        return $this->mcpSuccess($request, $this->respond(
            $data,
            $this->actionsForDeployment($uuid, $application->uuid),
        ), ['resource_uuid' => $uuid]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'uuid' => $schema->string()->description('Deployment UUID.')->required(),
        ];
    }
}
