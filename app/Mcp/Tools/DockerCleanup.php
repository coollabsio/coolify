<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(false)]
#[IsDestructive]
class DockerCleanup extends RestActionTool
{
    protected string $name = 'docker_cleanup';

    protected string $description = 'Read or change the Docker cleanup settings of a server of the authenticated team, list recent cleanup runs, or start a cleanup now. Requires read ability for get/executions, write ability for update (turning on delete_unused_volumes also needs deploy), deploy ability for run. Same validation and permissions as the REST API.';

    protected function actions(): array
    {
        return [
            'get' => ['method' => 'GET', 'path' => 'servers/{uuid}/docker-cleanup', 'ability' => 'read', 'description' => 'cleanup settings'],
            'update' => ['method' => 'PATCH', 'path' => 'servers/{uuid}/docker-cleanup', 'ability' => 'write', 'description' => 'change cleanup settings (data: docker_cleanup_frequency, docker_cleanup_threshold, force_docker_cleanup, delete_unused_volumes, delete_unused_networks, disable_application_image_retention; turning on delete_unused_volumes needs deploy ability)'],
            'executions' => ['method' => 'GET', 'path' => 'servers/{uuid}/docker-cleanup/executions', 'ability' => 'read', 'description' => 'last 20 cleanup runs'],
            'run' => ['method' => 'POST', 'path' => 'servers/{uuid}/docker-cleanup/run', 'ability' => 'deploy', 'confirm' => true, 'description' => 'start a cleanup now; removes unused images, and volumes/networks when enabled (data: delete_unused_volumes, delete_unused_networks)'],
        ];
    }

    protected function pathParams(): array
    {
        return [
            'uuid' => 'Server UUID.',
        ];
    }

    protected function dataDescription(): string
    {
        return 'Request body, same fields as the REST API: docker_cleanup_frequency, docker_cleanup_threshold, force_docker_cleanup, delete_unused_volumes, delete_unused_networks, disable_application_image_retention.';
    }
}
