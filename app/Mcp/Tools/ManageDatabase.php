<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(false)]
#[IsDestructive]
class ManageDatabase extends RestActionTool
{
    protected string $name = 'manage_database';

    protected string $description = 'Update, delete, clone, move, or import a backup into a standalone database of the authenticated team. Requires write ability; delete and import also need deploy, and so do instant_deploy, changing is_public, and clone_volumes. Same validation and permissions as the REST API.';

    protected function actions(): array
    {
        return [
            'update' => ['method' => 'PATCH', 'path' => 'databases/{uuid}', 'ability' => 'write', 'description' => 'update database settings (data: name, description, image, is_public, public_port, public_port_timeout, limits_*, type credentials and *_conf fields, redact_all_env_values_in_logs, instant_deploy; instant_deploy and changing is_public need deploy ability)'],
            'delete' => ['method' => 'DELETE', 'path' => 'databases/{uuid}', 'ability' => 'deploy', 'confirm' => true, 'description' => 'delete the database (data: delete_volumes, delete_connected_networks, delete_configurations, docker_cleanup; all default true)'],
            'clone' => ['method' => 'POST', 'path' => 'databases/{uuid}/clone', 'ability' => 'write', 'confirm' => true, 'description' => 'clone the database to a destination of the team (data: destination_uuid, name, clone_volumes; clone_volumes stops the source database while volumes are copied and needs deploy ability)'],
            'move' => ['method' => 'POST', 'path' => 'databases/{uuid}/move', 'ability' => 'write', 'confirm' => true, 'description' => 'move the database to another environment of the team (data: environment_uuid)'],
            'import' => ['method' => 'POST', 'path' => 'databases/{uuid}/imports', 'ability' => 'deploy', 'confirm' => true, 'description' => 'import a backup into the database, can overwrite data (data: source s3 | server, s3_storage_uuid, path, dump_all, replace_existing, keep_owners, restore_mysql_users, sqlite_database); check progress with get_database_import'],
        ];
    }

    protected function pathParams(): array
    {
        return [
            'uuid' => 'Database UUID.',
        ];
    }

    protected function dataDescription(): string
    {
        return 'Request body, same fields as the REST API (see the action descriptions). File uploads are not supported: import only from an S3 storage of the team (source=s3, s3_storage_uuid, path) or a server path (source=server, path).';
    }
}
