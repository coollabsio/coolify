<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Application;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class Deploy extends Tool
{
    protected string $name = 'deploy';

    protected string $description = 'Queue a deployment for a team-owned application by UUID. Requires deploy ability. Optional force rebuild and pull_request_id for previews.';

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

        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $uuid)->first();
        if (! $application) {
            return $this->mcpError($request, "Application [{$uuid}] not found.", ['resource_uuid' => $uuid]);
        }

        $force = filter_var($request->get('force'), FILTER_VALIDATE_BOOLEAN);
        $pullRequestId = (int) ($request->get('pull_request_id') ?? 0);
        $deploymentUuid = new_public_id();

        $result = queue_application_deployment(
            application: $application,
            deployment_uuid: $deploymentUuid,
            pull_request_id: $pullRequestId,
            force_rebuild: $force,
            is_api: true,
            no_questions_asked: true,
        );

        if (($result['status'] ?? null) === 'skipped' || ($result['status'] ?? null) === 'queue_full') {
            return $this->mcpSuccess($request, $this->respond([
                'ok' => false,
                'message' => $result['message'] ?? 'Deployment not queued.',
                'deployment_uuid' => null,
            ]), ['resource_uuid' => $uuid]);
        }

        auditLog('mcp.deploy', [
            'team_id' => $teamId,
            'application_uuid' => $uuid,
            'deployment_uuid' => $deploymentUuid,
            'force_rebuild' => $force,
            'pull_request_id' => $pullRequestId,
        ]);

        return $this->mcpSuccess($request, $this->respond([
            'ok' => true,
            'message' => 'Deployment request queued.',
            'application_uuid' => $uuid,
            'deployment_uuid' => $deploymentUuid,
            'force' => $force,
            'pull_request_id' => $pullRequestId,
            'next_tools' => [
                ['tool' => 'get_deployment', 'args' => ['uuid' => $deploymentUuid, 'include_log_summary' => true], 'hint' => 'Poll status / failure summary'],
            ],
        ]), ['resource_uuid' => $uuid, 'deployment_uuid' => $deploymentUuid]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'uuid' => $schema->string()->description('Application UUID.')->required(),
            'force' => $schema->boolean()->description('Force rebuild (default false).'),
            'pull_request_id' => $schema->integer()->description('Optional PR id for preview deploy.'),
        ];
    }
}
