<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(false)]
#[IsDestructive]
class ManageProject extends RestActionTool
{
    protected string $name = 'manage_project';

    protected string $description = 'Create, update, or delete a project, or create, update, or delete an environment in a project of the authenticated team. Requires write ability. Same validation and permissions as the REST API.';

    protected function actions(): array
    {
        return [
            'create' => ['method' => 'POST', 'path' => 'projects', 'ability' => 'write', 'description' => 'create a project (data: name, description)'],
            'update' => ['method' => 'PATCH', 'path' => 'projects/{uuid}', 'ability' => 'write', 'description' => 'update a project (data: name, description)'],
            'delete' => ['method' => 'DELETE', 'path' => 'projects/{uuid}', 'ability' => 'write', 'confirm' => true, 'description' => 'delete an empty project'],
            'create_environment' => ['method' => 'POST', 'path' => 'projects/{uuid}/environments', 'ability' => 'write', 'description' => 'create an environment (data: name)'],
            'update_environment' => ['method' => 'PATCH', 'path' => 'projects/{uuid}/environments/{environment_name_or_uuid}', 'ability' => 'write', 'description' => 'rename an environment (data: name)'],
            'delete_environment' => ['method' => 'DELETE', 'path' => 'projects/{uuid}/environments/{environment_name_or_uuid}', 'ability' => 'write', 'confirm' => true, 'description' => 'delete an empty environment'],
        ];
    }

    protected function pathParams(): array
    {
        return [
            'uuid' => 'Project UUID (not needed for create).',
            'environment_name_or_uuid' => 'Environment name or UUID (environment actions except create_environment).',
        ];
    }

    protected function dataDescription(): string
    {
        return 'Request body, same fields as the REST API: name, description.';
    }
}
