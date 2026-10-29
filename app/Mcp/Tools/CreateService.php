<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(false)]
#[IsDestructive]
class CreateService extends RestActionTool
{
    protected string $name = 'create_service';

    protected string $description = 'Create a service in a project of the authenticated team, from a one-click template (see list_service_templates) or a base64 encoded Docker Compose file. Requires write ability (instant_deploy also needs deploy). Same validation and permissions as the REST API.';

    protected function actions(): array
    {
        return [
            'template' => ['method' => 'POST', 'path' => 'services', 'ability' => 'write', 'description' => 'create from a one-click template (data: type)'],
            'compose' => ['method' => 'POST', 'path' => 'services', 'ability' => 'write', 'description' => 'create from a Docker Compose file (data: docker_compose_raw base64, connect_to_docker_network)'],
        ];
    }

    protected function pathParams(): array
    {
        return [];
    }

    protected function dataDescription(): string
    {
        return 'Request body, same fields as the REST API. Required: project_uuid, environment_name or environment_uuid, server_uuid; destination_uuid when the server has several destinations; type (template) or docker_compose_raw (compose, base64). '
            .'Optional: name, description, urls (list of {name, url}), force_domain_override, is_container_label_escape_enabled, redact_all_env_values_in_logs, tags, instant_deploy (needs deploy ability).';
    }
}
