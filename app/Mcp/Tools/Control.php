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
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Application;
use App\Models\Service;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Visus\Cuid2\Cuid2;

#[Name('control')]
#[Description('Start, stop, or restart an application, standalone database, or service resource by UUID.')]
class Control extends Tool
{
    use BuildsResponse;
    use ResolvesTeam;

    public function handle(Request $request): Response
    {
        if ($error = $this->ensureAbility($request, 'deploy')) {
            return $error;
        }

        $teamId = $this->resolveTeamId($request);
        if (is_null($teamId)) {
            return Response::error('Invalid token.');
        }

        $resourceType = $request->get('resource');
        $action = $request->get('action');
        $uuid = $request->get('uuid');

        if (! is_string($resourceType) || ! in_array($resourceType, ['application', 'database', 'service'])) {
            return Response::error('resource argument must be one of: application, database, service.');
        }

        if (! is_string($action) || ! in_array($action, ['start', 'stop', 'restart'])) {
            return Response::error('action argument must be one of: start, stop, restart.');
        }

        if (! is_string($uuid) || $uuid === '') {
            return Response::error('uuid argument is required.');
        }

        $resource = getResourceByUuid($uuid, $teamId);
        if (! $resource) {
            return Response::error(ucfirst($resourceType) . " [{$uuid}] not found or access denied.");
        }

        switch ($resourceType) {
            case 'application':
                if (! ($resource instanceof Application)) {
                    return Response::error("Resource [{$uuid}] is not an application.");
                }

                if ($action === 'start') {
                    if (str($resource->status)->contains('running')) {
                        return Response::error('Application is already running.');
                    }
                    $deployment_uuid = new Cuid2;
                    queue_application_deployment(
                        application: $resource,
                        deployment_uuid: $deployment_uuid,
                        force_rebuild: false,
                        is_api: true
                    );
                    return $this->respond([
                        'message' => 'Deployment request queued.',
                        'deployment_uuid' => $deployment_uuid->toString(),
                    ]);
                } elseif ($action === 'stop') {
                    if (str($resource->status)->contains('stopped') || str($resource->status)->contains('exited')) {
                        return Response::error('Application is already stopped.');
                    }
                    StopApplication::dispatch($resource, false, true);
                    return $this->respond(['message' => 'Application stopping request queued.']);
                } elseif ($action === 'restart') {
                    $deployment_uuid = new Cuid2;
                    queue_application_deployment(
                        application: $resource,
                        deployment_uuid: $deployment_uuid,
                        force_rebuild: true,
                        is_api: true
                    );
                    return $this->respond([
                        'message' => 'Restart request queued.',
                        'deployment_uuid' => $deployment_uuid->toString(),
                    ]);
                }
                break;

            case 'database':
                if ($resource instanceof \App\Models\ServiceDatabase) {
                    return Response::error('Databases inside services must be controlled via their parent service.');
                }

                if ($action === 'start') {
                    if (str($resource->status)->contains('running')) {
                        return Response::error('Database is already running.');
                    }
                    StartDatabase::dispatch($resource);
                    return $this->respond(['message' => 'Database starting request queued.']);
                } elseif ($action === 'stop') {
                    if (str($resource->status)->contains('stopped') || str($resource->status)->contains('exited')) {
                        return Response::error('Database is already stopped.');
                    }
                    StopDatabase::dispatch($resource, true);
                    return $this->respond(['message' => 'Database stopping request queued.']);
                } elseif ($action === 'restart') {
                    RestartDatabase::dispatch($resource);
                    return $this->respond(['message' => 'Database restarting request queued.']);
                }
                break;

            case 'service':
                if (! ($resource instanceof Service)) {
                    return Response::error("Resource [{$uuid}] is not a service.");
                }

                if ($action === 'start') {
                    if (str($resource->status)->contains('running')) {
                        return Response::error('Service is already running.');
                    }
                    StartService::dispatch($resource);
                    return $this->respond(['message' => 'Service starting request queued.']);
                } elseif ($action === 'stop') {
                    if (str($resource->status)->contains('stopped') || str($resource->status)->contains('exited')) {
                        return Response::error('Service is already stopped.');
                    }
                    StopService::dispatch($resource, false, true);
                    return $this->respond(['message' => 'Service stopping request queued.']);
                } elseif ($action === 'restart') {
                    RestartService::dispatch($resource, false);
                    return $this->respond(['message' => 'Service restarting request queued.']);
                }
                break;
        }

        return Response::error('Unhandled action or resource.');
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'resource' => $schema->string()->description('The resource type to control (application, database, service).')->required(),
            'action' => $schema->string()->description('The action to perform (start, stop, restart).')->required(),
            'uuid' => $schema->string()->description('The UUID of the resource to control.')->required(),
        ];
    }
}
