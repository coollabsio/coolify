<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(false)]
#[IsDestructive]
class ManageEnv extends RestActionTool
{
    protected string $name = 'manage_env';

    protected string $description = 'Create, update, bulk update, or delete environment variables of an application, database, or service of the authenticated team. Requires write ability. Values are only returned when the token also has read:sensitive. Same validation and permissions as the REST API.';

    protected function actions(): array
    {
        return [
            'create' => ['method' => 'POST', 'paths' => [
                'application' => 'applications/{uuid}/envs',
                'database' => 'databases/{uuid}/envs',
                'service' => 'services/{uuid}/envs',
            ], 'ability' => 'write', 'description' => 'create a variable (data: key, value, is_literal, is_multiline, is_shown_once, comment; applications also is_preview, is_runtime, is_buildtime)'],
            'update' => ['method' => 'PATCH', 'paths' => [
                'application' => 'applications/{uuid}/envs',
                'database' => 'databases/{uuid}/envs',
                'service' => 'services/{uuid}/envs',
            ], 'ability' => 'write', 'description' => 'update the variable with this key (data: key, value and the create flags)'],
            'bulk_update' => ['method' => 'PATCH', 'paths' => [
                'application' => 'applications/{uuid}/envs/bulk',
                'database' => 'databases/{uuid}/envs/bulk',
                'service' => 'services/{uuid}/envs/bulk',
            ], 'ability' => 'write', 'description' => 'create or update several variables (data: {"data": [{key, value, ...}]})'],
            'delete' => ['method' => 'DELETE', 'paths' => [
                'application' => 'applications/{uuid}/envs/{env_uuid}',
                'database' => 'databases/{uuid}/envs/{env_uuid}',
                'service' => 'services/{uuid}/envs/{env_uuid}',
            ], 'ability' => 'write', 'confirm' => true, 'description' => 'delete a variable by UUID'],
        ];
    }

    protected function pathParams(): array
    {
        return [
            'uuid' => 'Application, database, or service UUID.',
            'env_uuid' => 'Environment variable UUID (delete). See list_env_keys.',
        ];
    }

    protected function dataDescription(): string
    {
        return 'Request body, same fields as the REST API: key, value, is_literal, is_multiline, is_shown_once, comment; applications also is_preview, is_runtime, is_buildtime. bulk_update: {"data": [ ...variables ]}.';
    }
}
