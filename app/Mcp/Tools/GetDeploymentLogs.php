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

#[Name('get_deployment_logs')]
#[Description('Get the build/deploy log lines for a single deployment, windowed by page/per_page.')]
class GetDeploymentLogs extends Tool
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

        $deploymentUuid = $request->get('deployment_uuid');
        if (! is_string($deploymentUuid) || $deploymentUuid === '') {
            return Response::error('deployment_uuid argument is required.');
        }

        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $applicationUuid)->first();
        if (! $application) {
            return Response::error("Application [{$applicationUuid}] not found.");
        }

        $deployment = ApplicationDeploymentQueue::where('application_id', $application->id)
            ->where('deployment_uuid', $deploymentUuid)
            ->first();

        if (! $deployment) {
            return Response::error("Deployment [{$deploymentUuid}] not found.");
        }

        $lines = decode_remote_command_output($deployment, includeAll: false)->values();

        $args = $this->paginationArgs($request);
        $windowed = $lines->slice($args['offset'], $args['per_page'])->values()->all();

        $extra = ['application_uuid' => $applicationUuid, 'deployment_uuid' => $deploymentUuid];

        return $this->respond(
            [
                'deployment' => [
                    'uuid' => $deployment->deployment_uuid,
                    'status' => $deployment->status,
                    'commit' => $deployment->commit,
                ],
                'lines' => $windowed,
            ],
            [],
            $this->paginationMeta('get_deployment_logs', $args, $lines->count(), $extra),
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'application_uuid' => $schema->string()->description('Application UUID.')->required(),
            'deployment_uuid' => $schema->string()->description('Deployment UUID.')->required(),
            'page' => $schema->integer()->description('Page number (default 1).'),
            'per_page' => $schema->integer()->description('Log lines per page (default 50, max 100).'),
        ];
    }
}
