<?php

namespace App\Mcp\Tools;

use App\Actions\Application\StopApplication;
use App\Actions\Database\RestartDatabase;
use App\Actions\Database\StartDatabase;
use App\Actions\Database\StopDatabase;
use App\Actions\Service\RestartService;
use App\Actions\Service\StartService;
use App\Actions\Service\StopService;
use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesResource;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Application;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class Control extends Tool
{
    protected string $name = 'control';

    protected string $description = 'Start, stop, or restart an application, database, or service owned by the authenticated team. Requires deploy ability. Stop requires confirm=true.';

    use BuildsResponse;
    use ResolvesResource;
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

        $resourceType = $request->get('resource');
        $action = $request->get('action');
        $uuid = $request->get('uuid');
        $confirm = filter_var($request->get('confirm'), FILTER_VALIDATE_BOOLEAN);

        if (! is_string($resourceType) || ! in_array($resourceType, ['application', 'database', 'service'], true)) {
            return $this->mcpError($request, 'resource must be application, database, or service.');
        }
        if (! is_string($action) || ! in_array($action, ['start', 'stop', 'restart'], true)) {
            return $this->mcpError($request, 'action must be start, stop, or restart.');
        }
        if (! is_string($uuid) || $uuid === '') {
            return $this->mcpError($request, 'uuid argument is required.');
        }
        if ($action === 'stop' && ! $confirm) {
            return $this->mcpError($request, 'stop requires confirm=true to prevent accidental downtime.');
        }

        $resource = $this->resolveTeamResource($teamId, $resourceType, $uuid);
        if (! $resource) {
            return $this->mcpError($request, ucfirst($resourceType)." [{$uuid}] not found.", ['resource_uuid' => $uuid]);
        }

        try {
            $result = match ($resourceType) {
                'application' => $this->controlApplication($resource, $action),
                'database' => $this->controlDatabase($resource, $action),
                'service' => $this->controlService($resource, $action),
            };
        } catch (\Throwable $e) {
            return $this->mcpError($request, $e->getMessage(), ['resource_uuid' => $uuid]);
        }

        auditLog('mcp.control', [
            'team_id' => $teamId,
            'resource' => $resourceType,
            'action' => $action,
            'resource_uuid' => $uuid,
        ]);

        return $this->mcpSuccess($request, $this->respond([
            'ok' => true,
            'resource' => $resourceType,
            'uuid' => $uuid,
            'action' => $action,
            ...$result,
        ]), ['resource_uuid' => $uuid, 'action' => $action]);
    }

    /**
     * @return array<string, mixed>
     */
    private function controlApplication(Application $application, string $action): array
    {
        if ($action === 'stop') {
            StopApplication::dispatch($application, false, true);

            return ['message' => 'Application stopping request queued.'];
        }

        $deploymentUuid = new_public_id();
        $result = queue_application_deployment(
            application: $application,
            deployment_uuid: $deploymentUuid,
            force_rebuild: false,
            restart_only: $action === 'restart',
            is_api: true,
            no_questions_asked: $action === 'start',
        );

        if (($result['status'] ?? null) === 'skipped' || ($result['status'] ?? null) === 'queue_full') {
            return [
                'message' => $result['message'] ?? 'Deployment skipped.',
                'deployment_uuid' => null,
            ];
        }

        return [
            'message' => $action === 'restart' ? 'Restart request queued.' : 'Deployment request queued.',
            'deployment_uuid' => $deploymentUuid,
            'next_tools' => [
                ['tool' => 'get_deployment', 'args' => ['uuid' => $deploymentUuid], 'hint' => 'Poll deployment status'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function controlDatabase(mixed $database, string $action): array
    {
        if ($action === 'stop') {
            StopDatabase::dispatch($database);

            return ['message' => 'Database stopping request queued.'];
        }

        if ($action === 'start' && str($database->status ?? '')->contains('running')) {
            return ['message' => 'Database is already running.'];
        }

        if ($action === 'restart') {
            RestartDatabase::dispatch($database);

            return ['message' => 'Database restart request queued.'];
        }

        StartDatabase::dispatch($database);

        return ['message' => 'Database starting request queued.'];
    }

    /**
     * @return array<string, mixed>
     */
    private function controlService(mixed $service, string $action): array
    {
        if ($action === 'stop') {
            StopService::dispatch($service);

            return ['message' => 'Service stopping request queued.'];
        }

        if ($action === 'start' && str($service->status ?? '')->contains('running')) {
            return ['message' => 'Service is already running.'];
        }

        if ($action === 'restart') {
            RestartService::dispatch($service, false);

            return ['message' => 'Service restart request queued.'];
        }

        StartService::dispatch($service);

        return ['message' => 'Service starting request queued.'];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'resource' => $schema->string()->description('application | database | service')->required(),
            'action' => $schema->string()->description('start | stop | restart')->required(),
            'uuid' => $schema->string()->description('Resource UUID.')->required(),
            'confirm' => $schema->boolean()->description('Required true when action=stop.'),
        ];
    }
}
