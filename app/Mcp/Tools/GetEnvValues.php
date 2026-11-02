<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetEnvValues extends RestActionTool
{
    protected string $name = 'get_env_values';

    protected string $description = 'List environment variables with their values for an application, database, or service, or the shared variables of the team, a project, an environment, or a server of the authenticated team. Requires read and read:sensitive abilities and an admin or owner role. Values marked is_shown_once are never returned. Same validation and permissions as the REST API.';

    protected function actions(): array
    {
        return [
            'list' => ['method' => 'GET', 'paths' => [
                'application' => 'applications/{uuid}/envs',
                'database' => 'databases/{uuid}/envs',
                'service' => 'services/{uuid}/envs',
                'team' => 'team/envs',
                'project' => 'projects/{uuid}/envs',
                'environment' => 'projects/{uuid}/environments/{environment_name_or_uuid}/envs',
                'server' => 'servers/{uuid}/envs',
            ], 'ability' => 'read:sensitive', 'description' => 'variables with values'],
        ];
    }

    protected function pathParams(): array
    {
        return [
            'uuid' => 'Application, database, service, project, or server UUID. Not used for team.',
            'environment_name_or_uuid' => 'Environment name or UUID (resource environment).',
        ];
    }
}
