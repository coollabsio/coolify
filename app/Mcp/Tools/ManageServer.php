<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(false)]
#[IsDestructive]
class ManageServer extends RestActionTool
{
    protected string $name = 'manage_server';

    protected string $description = 'Add, update, delete, or validate a server of the authenticated team. Requires write ability; changing proxy_type, deleting with force or delete_from_provider, and validating with install also need deploy. Same validation and permissions as the REST API.';

    protected function actions(): array
    {
        return [
            'create' => ['method' => 'POST', 'path' => 'servers', 'ability' => 'write', 'description' => 'add a server (data: ip, private_key_uuid, name, description, port, user, server_role, proxy_type, instant_validate)'],
            'update' => ['method' => 'PATCH', 'path' => 'servers/{uuid}', 'ability' => 'write', 'description' => 'update a server (data: name, description, ip, port, user, private_key_uuid, server_role, proxy_type, concurrent_builds, dynamic_timeout, deployment_queue_limit, connection_timeout, is_terminal_enabled, server_disk_usage_*; changing proxy_type needs deploy ability)'],
            'delete' => ['method' => 'DELETE', 'path' => 'servers/{uuid}', 'ability' => 'write', 'confirm' => true, 'description' => 'delete a server without resources (the Coolify host cannot be deleted)'],
            'validate' => ['method' => 'POST', 'path' => 'servers/{uuid}/validate', 'ability' => 'write', 'description' => 'start a server validation (data: install=true also installs Docker and needs deploy ability)'],
        ];
    }

    protected function pathParams(): array
    {
        return [
            'uuid' => 'Server UUID (not needed for create).',
        ];
    }

    protected function dataDescription(): string
    {
        return 'Request body, same fields as the REST API: name, description, ip, port, user, private_key_uuid, server_role (deployment|build|both), proxy_type, instant_validate, install (validate only).';
    }
}
