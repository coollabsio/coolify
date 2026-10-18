<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(false)]
#[IsDestructive]
class ManageDestination extends RestActionTool
{
    protected string $name = 'manage_destination';

    protected string $description = 'Create a Docker network destination on a server of the authenticated team, rename one, or delete one without resources. Requires write ability. Same validation and permissions as the REST API.';

    protected function actions(): array
    {
        return [
            'create' => ['method' => 'POST', 'path' => 'servers/{server_uuid}/destinations', 'ability' => 'write', 'description' => 'create a destination on a server (data: network, name, type standalone|swarm)'],
            'update' => ['method' => 'PATCH', 'path' => 'destinations/{uuid}', 'ability' => 'write', 'description' => 'rename a destination (data: name)'],
            'delete' => ['method' => 'DELETE', 'path' => 'destinations/{uuid}', 'ability' => 'write', 'confirm' => true, 'description' => 'delete a destination without resources and remove its Docker network'],
        ];
    }

    protected function pathParams(): array
    {
        return [
            'server_uuid' => 'Server UUID (create only).',
            'uuid' => 'Destination UUID (update and delete).',
        ];
    }

    protected function dataDescription(): string
    {
        return 'Request body, same fields as the REST API: network, name, type (create); name (update).';
    }
}
