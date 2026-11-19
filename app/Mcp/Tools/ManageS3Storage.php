<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(false)]
#[IsDestructive]
class ManageS3Storage extends RestActionTool
{
    protected string $name = 'manage_s3_storage';

    protected string $description = 'List, get, add, update, delete, or test backup storages of the authenticated team: S3, SFTP, FTPS, or WebDAV (type, default s3; it cannot change after create). The secret key or password is never returned. Requires read ability for list/get, write ability for changes. Same validation and permissions as the REST API.';

    protected function actions(): array
    {
        return [
            'list' => ['method' => 'GET', 'path' => 's3-storages', 'ability' => 'read', 'description' => 'backup storages of the team'],
            'get' => ['method' => 'GET', 'path' => 's3-storages/{uuid}', 'ability' => 'read', 'description' => 'one backup storage (SFTP: private_key_uuid and the pinned sftp_host_key)'],
            'create' => ['method' => 'POST', 'path' => 's3-storages', 'ability' => 'write', 'description' => 'add a storage (data: type s3 | sftp | ftps | webdav, name, endpoint, key, secret, description, is_usable; S3 only: bucket, region, use_path_style_endpoint; SFTP only: private_key_uuid instead of secret)'],
            'update' => ['method' => 'PATCH', 'path' => 's3-storages/{uuid}', 'ability' => 'write', 'description' => 'update a storage (data: same fields as create except type, all optional; only the fields of the storage type)'],
            'delete' => ['method' => 'DELETE', 'path' => 's3-storages/{uuid}', 'ability' => 'delete', 'confirm' => true, 'description' => 'delete a storage'],
            'validate' => ['method' => 'POST', 'path' => 's3-storages/{uuid}/validate', 'ability' => 'write', 'description' => 'test the connection and save the result (SFTP: the first success pins the host key)'],
        ];
    }

    protected function pathParams(): array
    {
        return [
            'uuid' => 'Storage UUID (not needed for list and create).',
        ];
    }

    protected function dataDescription(): string
    {
        return 'Request body, same fields as the REST API: type (s3, sftp, ftps, webdav; create only, default s3), name, description, endpoint, key, secret, is_usable. S3 only: bucket, region, use_path_style_endpoint. SFTP only: private_key_uuid (a private key of the team, instead of the password). Endpoint: S3 URL; sftp://host:port/directory; ftps://host:port/directory; WebDAV https URL of the backup directory. key is the access key (S3) or the username; secret is the secret key (S3) or the password.';
    }
}
