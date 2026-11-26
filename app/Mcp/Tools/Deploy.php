<?php

namespace App\Mcp\Tools;

use App\Enums\CancelPreviousDeployments;
use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\CallsRestApi;
use App\Mcp\Concerns\ChecksTokenAbilities;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Application;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(false)]
#[IsDestructive]
class Deploy extends Tool
{
    protected string $name = 'deploy';

    protected string $description = 'Queue a deployment for a team-owned application by UUID, or deploy every resource of the authenticated team that has a tag (tag + confirm=true). Give exactly one of uuid or tag. Requires deploy ability. Optional force rebuild, pull_request_id for previews (uuid only), and cancel_previous to cancel older queued or building deployments.';

    use BuildsResponse;
    use CallsRestApi;
    use ChecksTokenAbilities;
    use ResolvesTeam;

    public function shouldRegister(): bool
    {
        return $this->currentTokenCan('deploy');
    }

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
        $tag = $request->get('tag');
        $hasUuid = $uuid !== null && $uuid !== '';
        $hasTag = $tag !== null && $tag !== '';
        if ($hasUuid === $hasTag) {
            return $this->mcpError($request, 'Give exactly one of uuid or tag.');
        }
        $cancelPrevious = CancelPreviousDeployments::tryFrom(strtolower(trim((string) ($request->get('cancel_previous') ?? 'none'))));
        if (! $cancelPrevious) {
            return $this->mcpError($request, 'cancel_previous must be one of: none, queued, building.');
        }
        if ($hasTag) {
            return $this->deployByTag($request, $teamId, $tag, $cancelPrevious);
        }
        if (! is_string($uuid)) {
            return $this->mcpError($request, 'uuid argument is required.');
        }

        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $uuid)->first();
        if (! $application) {
            return $this->mcpError($request, "Application [{$uuid}] not found.", ['resource_uuid' => $uuid]);
        }

        $force = filter_var($request->get('force'), FILTER_VALIDATE_BOOLEAN);
        $pullRequestId = (int) ($request->get('pull_request_id') ?? 0);
        if ($pullRequestId < 0 || $pullRequestId > 2147483647) {
            return $this->mcpError($request, 'pull_request_id must be between 0 and 2147483647.', ['resource_uuid' => $uuid]);
        }
        $deploymentUuid = new_public_id();

        $result = queue_application_deployment(
            application: $application,
            deployment_uuid: $deploymentUuid,
            pull_request_id: $pullRequestId,
            force_rebuild: $force,
            is_mcp: true,
            cancel_previous: $cancelPrevious,
        );

        if (($result['status'] ?? null) === 'skipped' || ($result['status'] ?? null) === 'queue_full') {
            return $this->mcpSuccess($request, $this->respond([
                'ok' => false,
                'message' => $result['message'] ?? 'Deployment not queued.',
                'deployment_uuid' => $result['deployment_uuid'] ?? null,
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

    /**
     * Deploys every resource of the token team with the tag through the REST deploy route.
     */
    private function deployByTag(Request $request, int $teamId, mixed $tag, CancelPreviousDeployments $cancelPrevious): Response
    {
        if (! is_string($tag)) {
            return $this->mcpError($request, 'tag must be a string.');
        }
        if ($request->get('pull_request_id') !== null) {
            return $this->mcpError($request, 'pull_request_id cannot be used with tag.');
        }
        if ($error = $this->ensureConfirmed($request, 'deploy by tag')) {
            return $error;
        }

        $force = filter_var($request->get('force'), FILTER_VALIDATE_BOOLEAN);

        try {
            $result = $this->callRestApi('POST', 'deploy', [], ['tag' => $tag, 'force' => $force, 'cancel_previous' => $cancelPrevious->value]);
        } catch (\InvalidArgumentException $e) {
            return $this->mcpError($request, $e->getMessage(), ['tag' => $tag]);
        }

        $body = is_array($result['body']) ? $result['body'] : [];
        $context = ['tag' => $tag, 'status' => $result['status']];
        if ($result['status'] >= 400) {
            return $this->mcpError($request, (string) ($body['message'] ?? 'Request failed.'), $context);
        }

        auditLog('mcp.deploy', [
            'team_id' => $teamId,
            'tag' => $tag,
            'force_rebuild' => $force,
        ]);

        return $this->mcpSuccess($request, $this->respond([
            'ok' => true,
            'tag' => $tag,
            'force' => $force,
            'messages' => $body['message'] ?? [],
            'deployments' => $body['details'] ?? [],
        ]), $context);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'uuid' => $schema->string()->description('Application UUID. Give either uuid or tag.'),
            'tag' => $schema->string()->description('Tag name: deploys every application and service of the authenticated team with this tag. Needs confirm=true.'),
            'force' => $schema->boolean()->description('Force rebuild (default false).'),
            'pull_request_id' => $schema->integer()->description('Optional PR id for preview deploy (uuid only).'),
            'confirm' => $schema->boolean()->description('Required true when deploying by tag.'),
            'cancel_previous' => $schema->string()->enum(['none', 'queued', 'building'])->description('Cancel older webhook, API, and MCP deployments of the same application, pull request, and server: queued ones, or also ones still building. A deployment that started to replace containers always finishes. Manual, restart, and rollback deployments are never cancelled. Default none.'),
        ];
    }
}
