<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('get_application_health')]
#[Description('Correlate an application\'s latest deployment outcome with its current runtime health and restart history in a single call. The recommended first step after a push to check whether a deploy succeeded and the resulting container is actually healthy.')]
class GetApplicationHealth extends Tool
{
    use BuildsResponse;
    use ResolvesTeam;

    public function handle(Request $request): Response
    {
        if ($error = $this->ensureAbility($request, 'read')) {
            return $error;
        }

        $teamId = $this->resolveTeamId($request);
        if (is_null($teamId)) {
            return Response::error('Invalid token.');
        }

        $applicationUuid = $request->get('application_uuid');
        if (! is_string($applicationUuid) || $applicationUuid === '') {
            return Response::error('application_uuid argument is required.');
        }

        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $applicationUuid)->first();
        if (! $application) {
            return Response::error("Application [{$applicationUuid}] not found.");
        }

        $lastDeployment = ApplicationDeploymentQueue::where('application_id', $application->id)
            ->orderByDesc('created_at')
            ->first();

        $data = [
            'application_uuid' => $applicationUuid,
            'status' => $application->status,
            'last_deployment' => $lastDeployment ? [
                'uuid' => $lastDeployment->deployment_uuid,
                'status' => $lastDeployment->status,
                'commit' => $lastDeployment->commit,
                'created_at' => $lastDeployment->created_at,
                'finished_at' => $lastDeployment->finished_at,
            ] : null,
            'restart_count' => $application->restart_count,
            'max_restart_count' => $application->max_restart_count,
            'last_restart_at' => $application->last_restart_at,
            'last_restart_type' => $application->last_restart_type,
            'stopped_after_restart_limit' => $application->stoppedAfterRestartLimit(),
        ];

        $actions = [];
        if ($lastDeployment && $lastDeployment->status === 'failed') {
            $actions[] = [
                'tool' => 'get_deployment_logs',
                'args' => ['application_uuid' => $applicationUuid, 'deployment_uuid' => $lastDeployment->deployment_uuid],
                'hint' => 'Build logs for the failed deployment',
            ];
        }
        $actions[] = [
            'tool' => 'get_application_logs',
            'args' => ['application_uuid' => $applicationUuid],
            'hint' => 'Current or last-captured container logs',
        ];

        return $this->respond($data, $actions);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'application_uuid' => $schema->string()->description('Application UUID.')->required(),
        ];
    }
}
