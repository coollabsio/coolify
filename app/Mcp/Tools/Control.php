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
use App\Mcp\Concerns\CallsRestApi;
use App\Mcp\Concerns\ChecksTokenAbilities;
use App\Mcp\Concerns\ResolvesResource;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Application;
use App\Models\EnvironmentVariable;
use App\Support\ResourceStartActivity;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(false)]
#[IsDestructive]
class Control extends Tool
{
    protected string $name = 'control';

    protected string $description = 'Start, stop, or restart an application, database, service, or a single service application or service database (service_uuid + uuid) owned by the authenticated team. Requires deploy ability. Stop requires confirm=true.';

    use BuildsResponse;
    use CallsRestApi;
    use ChecksTokenAbilities;
    use ResolvesResource;
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

        $resourceType = $request->get('resource');
        $action = $request->get('action');
        $uuid = $request->get('uuid');

        if (! is_string($resourceType) || ! in_array($resourceType, ['application', 'database', 'service', 'service_application', 'service_database'], true)) {
            return $this->mcpError($request, 'resource must be application, database, service, service_application, or service_database.');
        }
        if (! is_string($action) || ! in_array($action, ['start', 'stop', 'restart'], true)) {
            return $this->mcpError($request, 'action must be start, stop, or restart.');
        }
        if (! is_string($uuid) || $uuid === '') {
            return $this->mcpError($request, 'uuid argument is required.');
        }
        if ($action === 'stop' && $error = $this->ensureConfirmed($request, 'stop')) {
            return $error;
        }

        if ($resourceType === 'service_application' || $resourceType === 'service_database') {
            return $this->controlServiceChild($request, $teamId, $resourceType, $action, $uuid);
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
            $secrets = EnvironmentVariable::logRedactionSecretsFor($resource);

            return $this->mcpError($request, $this->redactLogText($e->getMessage(), $secrets), ['resource_uuid' => $uuid]);
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

        $prerequisiteError = StartDatabase::prerequisiteError($database);
        if ($prerequisiteError !== null) {
            throw new \RuntimeException($prerequisiteError);
        }

        $reservation = StartDatabase::reserveOperation($database);
        if ($reservation === null) {
            throw new \RuntimeException(ResourceStartActivity::DATABASE_OPERATION_IN_PROGRESS_MESSAGE);
        }

        if ($action === 'restart') {
            StartDatabase::dispatchReserved(RestartDatabase::class, $database, $reservation);

            return ['message' => 'Database restart request queued.'];
        }

        StartDatabase::dispatchReserved(StartDatabase::class, $database, $reservation);

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

    /**
     * Runs the REST route for a single service application or service database, with its own team scoping and policies.
     */
    private function controlServiceChild(Request $request, int $teamId, string $resourceType, string $action, string $uuid): Response
    {
        $serviceUuid = $request->get('service_uuid');
        if (! is_string($serviceUuid) || $serviceUuid === '') {
            return $this->mcpError($request, "service_uuid argument is required for {$resourceType}.");
        }

        $path = $resourceType === 'service_application'
            ? "services/{service_uuid}/applications/{uuid}/{$action}"
            : "services/{service_uuid}/databases/{uuid}/{$action}";

        try {
            $result = $this->callRestApi('POST', $path, ['service_uuid' => $serviceUuid, 'uuid' => $uuid]);
        } catch (\InvalidArgumentException $e) {
            return $this->mcpError($request, $e->getMessage(), ['resource_uuid' => $uuid]);
        }

        $body = is_array($result['body']) ? $result['body'] : [];
        $context = ['resource_uuid' => $uuid, 'service_uuid' => $serviceUuid, 'action' => $action, 'status' => $result['status']];
        if ($result['status'] >= 400) {
            return $this->mcpError($request, (string) ($body['message'] ?? 'Request failed.'), $context);
        }

        auditLog('mcp.control', [
            'team_id' => $teamId,
            'resource' => $resourceType,
            'action' => $action,
            'resource_uuid' => $uuid,
            'service_uuid' => $serviceUuid,
        ]);

        return $this->mcpSuccess($request, $this->respond([
            'ok' => true,
            'resource' => $resourceType,
            'uuid' => $uuid,
            'service_uuid' => $serviceUuid,
            'action' => $action,
            'message' => $body['message'] ?? null,
        ]), $context);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'resource' => $schema->string()->description('application | database | service | service_application | service_database')->required(),
            'action' => $schema->string()->description('start | stop | restart')->required(),
            'uuid' => $schema->string()->description('Resource UUID. For service_application / service_database: the UUID of the service application or service database.')->required(),
            'service_uuid' => $schema->string()->description('Parent service UUID. Required when resource is service_application or service_database.'),
            'confirm' => $schema->boolean()->description('Required true when action=stop.'),
        ];
    }
}
