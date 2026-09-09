<?php

namespace App\Mcp\Tools;

use App\Enums\ApplicationDeploymentStatus;
use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class CancelDeployment extends Tool
{
    protected string $name = 'cancel_deployment';

    protected string $description = 'Cancel a queued or in-progress deployment by deployment UUID for the authenticated team. Requires deploy ability.';

    use BuildsResponse;
    use ResolvesTeam;

    public function handle(Request $request): Response
    {
        if ($error = $this->ensureAbility($request, 'deploy', $this->name)) {
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

        // Match deployment_by_uuid: authorize by application ownership, not server ownership.
        // Server-scoped checks allow a shared-server host team to cancel another team's deployment.
        // Include soft-deleted apps so in-flight deploys remain cancellable after app delete.
        $application = $deployment->application()->withTrashed()->first();
        if (! $application || data_get($application->team(), 'id') !== (int) $teamId) {
            return $this->mcpError($request, "Deployment [{$uuid}] not found.", ['resource_uuid' => $uuid]);
        }

        $cancellable = [
            ApplicationDeploymentStatus::QUEUED->value,
            ApplicationDeploymentStatus::IN_PROGRESS->value,
        ];
        $deploymentUuid = $deployment->deployment_uuid;

        $updated = ApplicationDeploymentQueue::whereKey($deployment->getKey())
            ->whereIn('status', $cancellable)
            ->update(['status' => ApplicationDeploymentStatus::CANCELLED_BY_USER->value]);

        if ($updated !== 1) {
            $deployment->refresh();

            return $this->mcpError($request, "Deployment cannot be cancelled. Current status: {$deployment->status}", ['resource_uuid' => $uuid]);
        }

        $deployment->status = ApplicationDeploymentStatus::CANCELLED_BY_USER->value;

        try {
            $buildServerId = $deployment->build_server_id ?? $deployment->server_id;
            $server = Server::whereTeamId($teamId)->find($buildServerId);
            if ($server) {
                $deployment->addLogEntry('Deployment cancelled by user via MCP.', 'stderr');

                $checkCommand = "docker ps -a --filter name={$deploymentUuid} --format '{{.Names}}'";
                $containerExists = instant_remote_process([$checkCommand], $server);

                if ($containerExists && str($containerExists)->trim()->isNotEmpty()) {
                    instant_remote_process(["docker rm -f {$deploymentUuid}"], $server);
                    $deployment->addLogEntry('Deployment container stopped.');
                } else {
                    $deployment->addLogEntry('Deployment container not yet started. Will be cancelled when job checks status.');
                }

                // Parity with REST cancel_deployment: stop the remote build process if known.
                if ($deployment->current_process_id) {
                    try {
                        instant_remote_process(["kill -9 {$deployment->current_process_id}"], $server);
                    } catch (\Throwable) {
                        // Process might already be gone.
                    }
                }
            }
        } catch (\Throwable) {
            // Cancellation is still recorded even if remote kill fails.
        }

        auditLog('mcp.deployment.cancelled', [
            'team_id' => $teamId,
            'deployment_uuid' => $uuid,
            'application_id' => $deployment->application_id,
            'server_id' => $deployment->server_id,
        ]);

        try {
            $deploymentServer = Server::whereTeamId($teamId)->find($deployment->server_id);
            next_after_cancel($deploymentServer);
        } catch (\Throwable $e) {
            \Log::warning("Failed to advance deployment queue after cancelling deployment {$deployment->id}: {$e->getMessage()}");
        }

        return $this->mcpSuccess($request, $this->respond([
            'ok' => true,
            'message' => 'Deployment cancelled successfully.',
            'deployment_uuid' => $uuid,
            'status' => ApplicationDeploymentStatus::CANCELLED_BY_USER->value,
        ]), ['resource_uuid' => $uuid]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'uuid' => $schema->string()->description('Deployment UUID.')->required(),
        ];
    }
}
