<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetDatabaseImport extends RestActionTool
{
    protected string $name = 'get_database_import';

    protected string $description = 'Get the status of a backup import into a standalone database or a service database of the authenticated team. Requires read ability; the import output is only shown with read:sensitive. Same validation and permissions as the REST API.';

    protected function actions(): array
    {
        return [
            'get' => ['method' => 'GET', 'paths' => [
                'database' => 'databases/{uuid}/imports/{activity_id}',
                'service_database' => 'services/{uuid}/databases/{database_uuid}/imports/{activity_id}',
            ], 'ability' => 'read', 'description' => 'import status'],
        ];
    }

    protected function pathParams(): array
    {
        return [
            'uuid' => 'Database UUID (resource=database) or service UUID (resource=service_database).',
            'database_uuid' => 'Service database UUID (resource=service_database).',
            'activity_id' => 'Import ID returned by the import action.',
        ];
    }
}
