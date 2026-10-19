<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(false)]
#[IsDestructive]
class ManageApplication extends RestActionTool
{
    protected string $name = 'manage_application';

    protected string $description = 'Update, delete, clone, or move an application of the authenticated team, or add or remove an additional server destination. Requires write ability; delete, instant_deploy, manual webhook secrets, and clone_volumes also need deploy. Same validation and permissions as the REST API.';

    protected function actions(): array
    {
        return [
            'update' => ['method' => 'PATCH', 'path' => 'applications/{uuid}', 'ability' => 'write', 'description' => 'update application settings (data: same fields as the REST update, e.g. name, domains, git_branch, ports_exposes, build_command; instant_deploy and manual_webhook_secret_* need deploy ability)'],
            'delete' => ['method' => 'DELETE', 'path' => 'applications/{uuid}', 'ability' => 'deploy', 'confirm' => true, 'description' => 'delete the application and its containers (data: delete_volumes, delete_connected_networks, delete_configurations, docker_cleanup)'],
            'clone' => ['method' => 'POST', 'path' => 'applications/{uuid}/clone', 'ability' => 'write', 'description' => 'clone the application to a destination of the team (data: destination_uuid, name, clone_volumes; clone_volumes needs deploy ability)'],
            'move' => ['method' => 'POST', 'path' => 'applications/{uuid}/move', 'ability' => 'write', 'confirm' => true, 'description' => 'move the application to another environment of the team (data: environment_uuid)'],
            'add_server' => ['method' => 'POST', 'path' => 'applications/{uuid}/destinations', 'ability' => 'write', 'description' => 'attach an additional destination on another server (data: destination_uuid)'],
            'remove_server' => ['method' => 'DELETE', 'path' => 'applications/{uuid}/destinations/{destination_uuid}', 'ability' => 'write', 'confirm' => true, 'description' => 'detach an additional destination'],
        ];
    }

    protected function pathParams(): array
    {
        return [
            'uuid' => 'Application UUID.',
            'destination_uuid' => 'Destination UUID to detach (remove_server only).',
        ];
    }

    protected function dataDescription(): string
    {
        return 'Request body, same fields as the REST API. update: application fields; delete: delete_volumes, delete_connected_networks, delete_configurations, docker_cleanup; clone: destination_uuid, name, clone_volumes; move: environment_uuid; add_server: destination_uuid.';
    }
}
