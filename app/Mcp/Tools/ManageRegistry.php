<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(false)]
#[IsDestructive]
class ManageRegistry extends RestActionTool
{
    protected string $name = 'manage_registry';

    protected string $description = 'List, add, check, or remove Docker registry logins on a server of the authenticated team. Passwords are never returned. Requires read:sensitive ability for list, deploy ability for create, write ability for check and delete. Same validation and permissions as the REST API.';

    protected function actions(): array
    {
        return [
            'list' => ['method' => 'GET', 'path' => 'servers/{uuid}/registries', 'ability' => 'read:sensitive', 'description' => 'registry logins on the server (registry and username only)'],
            'create' => ['method' => 'POST', 'path' => 'servers/{uuid}/registries', 'ability' => 'deploy', 'description' => 'log in to a registry on the server (data: registry, username, password)'],
            'check' => ['method' => 'POST', 'path' => 'servers/{uuid}/registries/{registry}/check', 'ability' => 'write', 'description' => 'check that a registry login works'],
            'delete' => ['method' => 'DELETE', 'path' => 'servers/{uuid}/registries/{registry}', 'ability' => 'write', 'confirm' => true, 'description' => 'log out of a registry; private image pulls can fail afterwards'],
        ];
    }

    protected function pathParams(): array
    {
        return [
            'uuid' => 'Server UUID.',
            'registry' => 'Registry host, for example ghcr.io (check and delete). Hosts with a port are not supported here.',
        ];
    }

    protected function dataDescription(): string
    {
        return 'Request body, same fields as the REST API: registry, username, password (create).';
    }
}
