<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(false)]
#[IsDestructive]
class ManageS3Storage extends RestActionTool
{
    protected string $name = 'manage_s3_storage';

    protected string $description = 'List, get, add, update, delete, or test S3 storages of the authenticated team. The secret key is never returned. Requires read ability for list/get, write ability for changes. Same validation and permissions as the REST API.';

    protected function actions(): array
    {
        return [
            'list' => ['method' => 'GET', 'path' => 's3-storages', 'ability' => 'read', 'description' => 'S3 storages of the team'],
            'get' => ['method' => 'GET', 'path' => 's3-storages/{uuid}', 'ability' => 'read', 'description' => 'one S3 storage'],
            'create' => ['method' => 'POST', 'path' => 's3-storages', 'ability' => 'write', 'description' => 'add an S3 storage (data: name, endpoint, bucket, region, key, secret, description, is_usable)'],
            'update' => ['method' => 'PATCH', 'path' => 's3-storages/{uuid}', 'ability' => 'write', 'description' => 'update an S3 storage (data: same fields as create, all optional)'],
            'delete' => ['method' => 'DELETE', 'path' => 's3-storages/{uuid}', 'ability' => 'write', 'confirm' => true, 'description' => 'delete an S3 storage'],
            'validate' => ['method' => 'POST', 'path' => 's3-storages/{uuid}/validate', 'ability' => 'write', 'description' => 'test the connection and save the result'],
        ];
    }

    protected function pathParams(): array
    {
        return [
            'uuid' => 'S3 storage UUID (not needed for list and create).',
        ];
    }

    protected function dataDescription(): string
    {
        return 'Request body, same fields as the REST API: name, description, endpoint, bucket, region, key, secret, is_usable.';
    }
}
