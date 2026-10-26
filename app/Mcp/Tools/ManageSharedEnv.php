<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(false)]
#[IsDestructive]
class ManageSharedEnv extends RestActionTool
{
    protected string $name = 'manage_shared_env';

    protected string $description = 'Create, update, or delete shared environment variables of the authenticated team, or of one of its projects, environments, or servers. Requires write ability. Same validation and permissions as the REST API.';

    protected function actions(): array
    {
        return [
            'create' => ['method' => 'POST', 'paths' => [
                'team' => 'team/envs',
                'project' => 'projects/{uuid}/envs',
                'environment' => 'projects/{uuid}/environments/{environment_name_or_uuid}/envs',
                'server' => 'servers/{uuid}/envs',
            ], 'ability' => 'write', 'description' => 'create a shared variable (data: key, value, is_literal, is_multiline, is_shown_once, comment); returns its id'],
            'update' => ['method' => 'PATCH', 'paths' => [
                'team' => 'team/envs/{env_id}',
                'project' => 'projects/{uuid}/envs/{env_id}',
                'environment' => 'projects/{uuid}/environments/{environment_name_or_uuid}/envs/{env_id}',
                'server' => 'servers/{uuid}/envs/{env_id}',
            ], 'ability' => 'write', 'description' => 'update a shared variable (data: key, value, is_literal, is_multiline, is_shown_once, comment)'],
            'delete' => ['method' => 'DELETE', 'paths' => [
                'team' => 'team/envs/{env_id}',
                'project' => 'projects/{uuid}/envs/{env_id}',
                'environment' => 'projects/{uuid}/environments/{environment_name_or_uuid}/envs/{env_id}',
                'server' => 'servers/{uuid}/envs/{env_id}',
            ], 'ability' => 'write', 'confirm' => true, 'description' => 'delete a shared variable'],
        ];
    }

    protected function pathParams(): array
    {
        return [
            'uuid' => 'Project UUID (resource project or environment) or server UUID (resource server). Not used for team.',
            'environment_name_or_uuid' => 'Environment name or UUID (resource environment).',
            'env_id' => 'Shared variable id (update, delete).',
        ];
    }

    protected function dataDescription(): string
    {
        return 'Request body, same fields as the REST API: key, value, is_literal, is_multiline, is_shown_once, comment.';
    }
}
