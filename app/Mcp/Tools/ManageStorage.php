<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(false)]
#[IsDestructive]
class ManageStorage extends RestActionTool
{
    protected string $name = 'manage_storage';

    protected string $description = 'Create, update, or delete a persistent volume or file storage of an application, database, or service of the authenticated team, and set, remove, or run its volume backup schedule. Requires write ability; run_backup also requires deploy, and so do file content, mounts outside the resource directory, and stop_during_backup. Same validation and permissions as the REST API.';

    protected function actions(): array
    {
        return [
            'create' => ['method' => 'POST', 'paths' => $this->paths('storages'), 'ability' => 'write', 'description' => 'create a storage (data: type persistent|file, name, mount_path, content, is_directory, is_host_file, fs_path; services also resource_uuid; file content and an fs_path outside the resource directory need deploy ability)'],
            'update' => ['method' => 'PATCH', 'paths' => $this->paths('storages'), 'ability' => 'write', 'description' => 'update a storage (data: uuid of the storage, type, name, mount_path, content, is_preview_suffix_enabled; content of a file outside the resource directory needs deploy ability)'],
            'delete' => ['method' => 'DELETE', 'paths' => $this->paths('storages/{storage_uuid}'), 'ability' => 'write', 'confirm' => true, 'description' => 'delete a storage'],
            'set_backup' => ['method' => 'PUT', 'paths' => $this->paths('storages/{storage_uuid}/backups'), 'ability' => 'write', 'description' => 'create or replace the volume backup schedule (data: frequency, enabled, save_s3, s3_storage_uuid(s), stop_during_backup, retention fields ...; stop_during_backup needs deploy ability)'],
            'remove_backup' => ['method' => 'DELETE', 'paths' => $this->paths('storages/{storage_uuid}/backups'), 'ability' => 'write', 'confirm' => true, 'description' => 'delete the volume backup schedule and its local and S3 archives'],
            'run_backup' => ['method' => 'POST', 'paths' => $this->paths('storages/{storage_uuid}/backups/run'), 'ability' => 'deploy', 'confirm' => true, 'description' => 'run the volume backup now (can stop the resource when stop_during_backup is set)'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function paths(string $suffix): array
    {
        return [
            'application' => "applications/{uuid}/{$suffix}",
            'database' => "databases/{uuid}/{$suffix}",
            'service' => "services/{uuid}/{$suffix}",
        ];
    }

    protected function pathParams(): array
    {
        return [
            'uuid' => 'Application, database, or service UUID.',
            'storage_uuid' => 'Storage UUID (delete and backup actions).',
        ];
    }

    protected function dataDescription(): string
    {
        return 'Request body, same fields as the REST API. Storage: type, name, mount_path, content, is_directory, is_host_file, fs_path, resource_uuid (service), uuid (update). Backup: frequency, enabled, save_s3, disable_local_backup, stop_during_backup, s3_storage_uuid, s3_storage_uuids, retention_amount_locally, retention_days_locally, retention_max_storage_locally, retention_amount_s3, retention_days_s3, retention_max_storage_s3, timeout, missing_backup_notification_days.';
    }
}
