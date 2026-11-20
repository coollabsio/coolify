<?php

namespace App\Ai\Tools;

use App\Actions\Application\StopApplication;
use App\Actions\Database\RestartDatabase;
use App\Actions\Database\StartDatabase;
use App\Actions\Database\StopDatabase;
use App\Actions\Service\RestartService;
use App\Actions\Service\StartService;
use App\Actions\Service\StopService;
use App\Ai\Concerns\AuthorizesToolAction;
use App\Mcp\Concerns\ResolvesResource;
use App\Models\Application;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class ControlResource implements Tool
{
    use AuthorizesToolAction;
    use ResolvesResource;

    public function description(): string
    {
        return 'Start, stop, or restart an application, database, or service in the current team. '
            .'Reversible operation; requires admin or owner role. Provide resource (application|database|service), action (start|stop|restart), and the resource uuid.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'resource' => $schema->string()->description('application | database | service')->required(),
            'action' => $schema->string()->description('start | stop | restart')->required(),
            'uuid' => $schema->string()->description('Resource UUID.')->required(),
        ];
    }

    public function handle(Request $request): string
    {
        $args = $request->validate([
            'resource' => 'required|in:application,database,service',
            'action' => 'required|in:start,stop,restart',
            'uuid' => 'required|string',
        ]);

        $target = $this->resolveTeamResource($this->actingTeamId(), $args['resource'], $args['uuid']);
        if (! $target) {
            return "The {$args['resource']} [{$args['uuid']}] was not found in this team.";
        }

        $this->authorizeToolAction('update', $target, 'control_resource');

        $message = match ($args['resource']) {
            'application' => $this->controlApplication($target, $args['action']),
            'database' => $this->controlDatabase($target, $args['action']),
            'service' => $this->controlService($target, $args['action']),
        };

        $this->auditToolCall('control_resource', 'success', [
            'resource' => $args['resource'],
            'action' => $args['action'],
            'resource_uuid' => $args['uuid'],
        ]);

        return $message;
    }

    private function controlApplication(Application $application, string $action): string
    {
        if ($action === 'stop') {
            StopApplication::dispatch($application, false, true);

            return 'Application stop queued.';
        }

        $deploymentUuid = new_public_id();
        queue_application_deployment(
            application: $application,
            deployment_uuid: $deploymentUuid,
            force_rebuild: false,
            restart_only: $action === 'restart',
            is_api: true,
            no_questions_asked: $action === 'start',
        );

        return ($action === 'restart' ? 'Application restart' : 'Application start')." queued (deployment {$deploymentUuid}).";
    }

    private function controlDatabase(mixed $database, string $action): string
    {
        match ($action) {
            'stop' => StopDatabase::dispatch($database),
            'restart' => RestartDatabase::dispatch($database),
            default => StartDatabase::dispatch($database),
        };

        return "Database {$action} queued.";
    }

    private function controlService(mixed $service, string $action): string
    {
        match ($action) {
            'stop' => StopService::dispatch($service),
            'restart' => RestartService::dispatch($service, false),
            default => StartService::dispatch($service),
        };

        return "Service {$action} queued.";
    }
}
