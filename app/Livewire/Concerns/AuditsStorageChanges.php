<?php

namespace App\Livewire\Concerns;

use App\Models\Application;
use App\Models\LocalFileVolume;
use App\Models\LocalPersistentVolume;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use Illuminate\Database\Eloquent\Model;

/**
 * Records `ui.{application|service|database}.storage_*` audit events that mirror the storage API events.
 * File content is never part of the context.
 */
trait AuditsStorageChanges
{
    /**
     * @param  'created'|'updated'|'deleted'  $action
     * @param  array<string, mixed>  $extra
     */
    protected function auditStorageChange(Model $resource, string $action, LocalPersistentVolume|LocalFileVolume $storage, array $extra = []): void
    {
        [$resourceKey, $resourceContext] = $this->storageAuditResourceContext($resource);

        auditLog("ui.{$resourceKey}.storage_{$action}", array_merge([
            'team_id' => $resource->team()?->id,
        ], $resourceContext, [
            'storage_uuid' => $storage->uuid ?? null,
            'storage_id' => $storage->id,
            'storage_type' => $this->storageAuditType($storage),
            'storage_name' => $storage instanceof LocalPersistentVolume ? $storage->name : null,
            'mount_path' => $storage->mount_path,
            'host_path' => $storage instanceof LocalFileVolume ? $storage->fs_path : $storage->host_path,
        ], $extra));
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function storageAuditResourceContext(Model $resource): array
    {
        if ($resource instanceof Application) {
            return ['application', [
                'application_uuid' => $resource->uuid,
                'application_name' => $resource->name,
            ]];
        }

        if ($resource instanceof ServiceApplication || $resource instanceof ServiceDatabase) {
            $subResourceKey = $resource instanceof ServiceApplication ? 'service_application' : 'service_database';

            return ['service', [
                'service_uuid' => $resource->service?->uuid,
                'service_name' => $resource->service?->name,
                "{$subResourceKey}_uuid" => $resource->uuid,
                "{$subResourceKey}_name" => $resource->name,
            ]];
        }

        return ['database', [
            'database_uuid' => $resource->getAttribute('uuid'),
            'database_name' => $resource->getAttribute('name'),
        ]];
    }

    private function storageAuditType(LocalPersistentVolume|LocalFileVolume $storage): string
    {
        if ($storage instanceof LocalPersistentVolume) {
            return 'persistent';
        }

        return match (true) {
            (bool) $storage->is_host_file => 'host_file',
            (bool) $storage->is_directory => 'directory',
            default => 'file',
        };
    }
}
