<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(false)]
#[IsDestructive]
class ManageTags extends RestActionTool
{
    protected string $name = 'manage_tags';

    protected string $description = 'Create, rename, or delete a tag of the authenticated team, or attach tags to and detach a tag from an application, database, or service. Requires write ability. Same validation and permissions as the REST API.';

    protected function actions(): array
    {
        return [
            'create' => ['method' => 'POST', 'path' => 'tags', 'ability' => 'write', 'description' => 'create a team tag (data: name)'],
            'update' => ['method' => 'PATCH', 'path' => 'tags/{tag_uuid}', 'ability' => 'write', 'description' => 'rename a team tag (data: name)'],
            'delete' => ['method' => 'DELETE', 'path' => 'tags/{tag_uuid}', 'ability' => 'write', 'confirm' => true, 'description' => 'delete a team tag and detach it from all resources'],
            'attach' => ['method' => 'POST', 'paths' => [
                'application' => 'applications/{uuid}/tags',
                'database' => 'databases/{uuid}/tags',
                'service' => 'services/{uuid}/tags',
            ], 'ability' => 'write', 'description' => 'attach tags by name, missing team tags are created (data: tag_name or tag_names)'],
            'detach' => ['method' => 'DELETE', 'paths' => [
                'application' => 'applications/{uuid}/tags/{tag_uuid}',
                'database' => 'databases/{uuid}/tags/{tag_uuid}',
                'service' => 'services/{uuid}/tags/{tag_uuid}',
            ], 'ability' => 'write', 'description' => 'detach a tag from the resource'],
        ];
    }

    protected function pathParams(): array
    {
        return [
            'uuid' => 'Application, database, or service UUID (attach and detach).',
            'tag_uuid' => 'Tag UUID (update, delete, detach).',
        ];
    }

    protected function dataDescription(): string
    {
        return 'Request body, same fields as the REST API: name (create, update), tag_name or tag_names (attach).';
    }
}
