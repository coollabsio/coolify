<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(false)]
#[IsDestructive]
class ManageBackup extends RestActionTool
{
    protected string $name = 'manage_backup';

    protected string $description = 'Create, update, or delete a backup schedule of a database of the authenticated team, or delete one backup execution. Set data.backup_now=true on create or update to run a backup now (requires deploy ability). Requires write ability. Same validation and permissions as the REST API.';

    protected function actions(): array
    {
        return [
            'create' => ['method' => 'POST', 'path' => 'databases/{uuid}/backups', 'ability' => 'write', 'description' => 'create a backup schedule (data: frequency, enabled, save_s3, s3_storage_uuid(s), retention fields, backup_now ...; backup_now needs deploy ability)'],
            'update' => ['method' => 'PATCH', 'path' => 'databases/{uuid}/backups/{scheduled_backup_uuid}', 'ability' => 'write', 'description' => 'update a backup schedule; data.backup_now=true runs a backup now and needs deploy ability'],
            'delete' => ['method' => 'DELETE', 'path' => 'databases/{uuid}/backups/{scheduled_backup_uuid}', 'ability' => 'write', 'confirm' => true, 'description' => 'delete a backup schedule and all its backup files (data.delete_s3=true also deletes S3 copies)'],
            'delete_execution' => ['method' => 'DELETE', 'path' => 'databases/{uuid}/backups/{scheduled_backup_uuid}/executions/{execution_uuid}', 'ability' => 'write', 'confirm' => true, 'description' => 'delete one backup execution and its file (data.delete_s3=true also deletes the S3 copy)'],
        ];
    }

    protected function pathParams(): array
    {
        return [
            'uuid' => 'Database UUID.',
            'scheduled_backup_uuid' => 'Backup schedule UUID (not needed for create).',
            'execution_uuid' => 'Backup execution UUID (delete_execution only).',
        ];
    }

    protected function dataDescription(): string
    {
        return 'Request body, same fields as the REST API: frequency, enabled, save_s3, dump_all, backup_now, s3_storage_uuid, s3_storage_uuids, databases_to_backup, database_backup_retention_amount_locally, database_backup_retention_days_locally, database_backup_retention_max_storage_locally, database_backup_retention_amount_s3, database_backup_retention_days_s3, database_backup_retention_max_storage_s3, timeout, missing_backup_notification_days; delete_s3 for deletes.';
    }
}
