<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListRollbackImages extends RestActionTool
{
    protected string $name = 'list_rollback_images';

    protected string $description = 'List the Docker images on the server that an application of the authenticated team can be rolled back to. Requires read ability. Same validation and permissions as the REST API.';

    protected function actions(): array
    {
        return [
            'list' => ['method' => 'GET', 'path' => 'applications/{uuid}/rollback-images', 'ability' => 'read', 'description' => 'list rollback images and the current tag'],
        ];
    }

    protected function pathParams(): array
    {
        return [
            'uuid' => 'Application UUID.',
        ];
    }
}
