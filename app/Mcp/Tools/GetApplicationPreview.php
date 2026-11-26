<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetApplicationPreview extends RestActionTool
{
    protected string $name = 'get_application_preview';

    protected string $description = 'Get a pull request preview deployment of an application of the authenticated team, or its container logs. get requires read ability, logs require read:sensitive. Same validation and permissions as the REST API.';

    protected function actions(): array
    {
        return [
            'get' => ['method' => 'GET', 'path' => 'applications/{uuid}/previews/{pull_request_id}', 'ability' => 'read', 'description' => 'preview details'],
            'logs' => ['method' => 'GET', 'path' => 'applications/{uuid}/previews/{pull_request_id}/logs', 'ability' => 'read:sensitive', 'description' => 'preview container logs (data: lines, show_timestamps, service_name)'],
        ];
    }

    protected function pathParams(): array
    {
        return [
            'uuid' => 'Application UUID.',
            'pull_request_id' => 'Pull request ID of the preview.',
        ];
    }

    protected function dataDescription(): string
    {
        return 'Query fields for logs, same as the REST API: lines, show_timestamps, service_name.';
    }
}
