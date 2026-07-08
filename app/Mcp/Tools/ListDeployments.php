<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Application;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('list_deployments')]
#[Description('List deployment history for an application, newest first. Use get_deployment_logs for the build output of a specific deployment.')]
class ListDeployments extends Tool
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

        $args = $this->paginationArgs($request);

        $result = $application->deployments($args['offset'], $args['per_page']);

        $summaries = collect($result['deployments'])
            ->map(fn ($deployment) => [
                'uuid' => $deployment->deployment_uuid,
                'status' => $deployment->status,
                'commit' => $deployment->commit,
                'commit_message' => $deployment->commit_message,
                'pull_request_id' => $deployment->pull_request_id,
                'is_webhook' => $deployment->is_webhook,
                'created_at' => $deployment->created_at,
                'finished_at' => $deployment->finished_at,
            ])
            ->values()
            ->all();

        $actions = collect($summaries)->map(fn ($deployment) => [
            'tool' => 'get_deployment_logs',
            'args' => ['application_uuid' => $applicationUuid, 'deployment_uuid' => $deployment['uuid']],
            'hint' => "Build logs for deployment {$deployment['uuid']}",
        ])->all();

        return $this->respond(
            $summaries,
            $actions,
            $this->paginationMeta('list_deployments', $args, $result['count'], ['application_uuid' => $applicationUuid]),
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'application_uuid' => $schema->string()->description('Application UUID.')->required(),
            'page' => $schema->integer()->description('Page number (default 1).'),
            'per_page' => $schema->integer()->description('Items per page (default 50, max 100).'),
        ];
    }
}
