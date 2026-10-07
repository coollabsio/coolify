<?php

namespace App\Livewire\Storage;

use App\Models\Application;
use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledVolumeBackup;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Resources extends Component
{
    use AuthorizesRequests;

    public S3Storage $storage;

    public array $selectedStorages = [];

    public array $selectedVolumeStorages = [];

    public function mount(): void
    {
        $this->authorize('view', $this->storage);

        $backups = ScheduledDatabaseBackup::where('s3_storage_id', $this->storage->id)
            ->where('save_s3', true)
            ->get();

        foreach ($backups as $backup) {
            $this->selectedStorages[$backup->id] = $this->storage->id;
        }

        ScheduledVolumeBackup::query()
            ->where('s3_storage_id', $this->storage->id)
            ->where('save_s3', true)
            ->each(function (ScheduledVolumeBackup $backup): void {
                $this->selectedVolumeStorages[$backup->id] = $this->storage->id;
            });
    }

    public function disableS3(int $backupId): void
    {
        $this->authorize('update', $this->storage);

        $backup = ScheduledDatabaseBackup::where('id', $backupId)
            ->where('s3_storage_id', $this->storage->id)
            ->firstOrFail();

        $backup->fill([
            'save_s3' => false,
            's3_storage_id' => null,
        ]);
        $changedFields = auditChangedFields($backup);
        $backup->save();

        $this->auditDatabaseBackupUpdated($backup, $changedFields);
        unset($this->selectedStorages[$backupId]);

        $this->dispatch('success', 'S3 disabled.', 'S3 backup has been disabled for this schedule.');
    }

    public function moveBackup(int $backupId): void
    {
        $this->authorize('update', $this->storage);

        $backup = ScheduledDatabaseBackup::where('id', $backupId)
            ->where('s3_storage_id', $this->storage->id)
            ->firstOrFail();
        $newStorageId = $this->selectedStorages[$backupId] ?? null;

        if (! $newStorageId || (int) $newStorageId === $this->storage->id) {
            $this->dispatch('error', 'No change.', 'The backup is already using this storage.');

            return;
        }

        $newStorage = S3Storage::where('id', $newStorageId)
            ->where('team_id', $this->storage->team_id)
            ->first();

        if (! $newStorage) {
            $this->dispatch('error', 'Storage not found.');

            return;
        }

        $this->authorize('update', $newStorage);

        $backup->s3_storage_id = $newStorage->id;
        $changedFields = auditChangedFields($backup);
        $backup->save();

        $this->auditDatabaseBackupUpdated($backup, $changedFields);
        unset($this->selectedStorages[$backupId]);

        $this->dispatch('success', 'Backup moved.', "Moved to {$newStorage->name}.");
    }

    public function disableVolumeS3(int $backupId): void
    {
        $this->authorize('update', $this->storage);

        $backup = ScheduledVolumeBackup::query()
            ->where('id', $backupId)
            ->where('s3_storage_id', $this->storage->id)
            ->firstOrFail();

        $backup->fill([
            'save_s3' => false,
            's3_storage_id' => null,
        ]);
        $changedFields = auditChangedFields($backup);
        $backup->save();

        $this->auditVolumeBackupUpdated($backup, $changedFields);
        unset($this->selectedVolumeStorages[$backupId]);

        $this->dispatch('success', 'S3 disabled.', 'S3 backup has been disabled for this schedule.');
    }

    public function moveVolumeBackup(int $backupId): void
    {
        $this->authorize('update', $this->storage);

        $backup = ScheduledVolumeBackup::query()
            ->where('id', $backupId)
            ->where('s3_storage_id', $this->storage->id)
            ->firstOrFail();
        $newStorageId = $this->selectedVolumeStorages[$backupId] ?? null;

        if (! $newStorageId || (int) $newStorageId === $this->storage->id) {
            $this->dispatch('error', 'No change.', 'The backup is already using this storage.');

            return;
        }

        $newStorage = S3Storage::query()
            ->where('id', $newStorageId)
            ->where('team_id', $this->storage->team_id)
            ->first();

        if (! $newStorage) {
            $this->dispatch('error', 'Storage not found.');

            return;
        }

        $this->authorize('update', $newStorage);

        $backup->s3_storage_id = $newStorage->id;
        $changedFields = auditChangedFields($backup);
        $backup->save();

        $this->auditVolumeBackupUpdated($backup, $changedFields);
        unset($this->selectedVolumeStorages[$backupId]);

        $this->dispatch('success', 'Backup moved.', "Moved to {$newStorage->name}.");
    }

    /**
     * @param  array<int, string>  $changedFields
     */
    private function auditDatabaseBackupUpdated(ScheduledDatabaseBackup $backup, array $changedFields): void
    {
        if ($changedFields === []) {
            return;
        }

        $database = $backup->database;
        auditLog('ui.database.backup_schedule_updated', [
            'team_id' => $this->storage->team_id,
            'database_uuid' => $database?->uuid,
            'database_name' => $database?->name,
            'backup_uuid' => $backup->uuid,
            'changed_fields' => $changedFields,
        ]);
    }

    /**
     * @param  array<int, string>  $changedFields
     */
    private function auditVolumeBackupUpdated(ScheduledVolumeBackup $backup, array $changedFields): void
    {
        if ($changedFields === []) {
            return;
        }

        $resource = $backup->targetResource();
        if ($resource instanceof ServiceApplication || $resource instanceof ServiceDatabase) {
            $resource = $resource->service;
        }

        auditLog('ui.volume_backup.schedule_set', [
            'team_id' => $this->storage->team_id,
            'resource_type' => match (true) {
                $resource instanceof Application => 'application',
                $resource instanceof Service => 'service',
                default => 'database',
            },
            'resource_uuid' => $resource?->uuid,
            'resource_name' => $resource?->name,
            'storage_uuid' => $backup->backupable?->uuid,
            'backup_uuid' => $backup->uuid,
            'changed_fields' => $changedFields,
        ]);
    }

    public function render()
    {
        $backups = ScheduledDatabaseBackup::where('s3_storage_id', $this->storage->id)
            ->where('save_s3', true)
            ->with('database')
            ->get()
            ->groupBy(fn ($backup) => $backup->database_type.'-'.$backup->database_id);

        $allStorages = S3Storage::where('team_id', $this->storage->team_id)
            ->orderBy('name')
            ->get(['id', 'name', 'is_usable']);

        $volumeBackups = ScheduledVolumeBackup::query()
            ->where('s3_storage_id', $this->storage->id)
            ->where('save_s3', true)
            ->with('backupable.resource')
            ->get();

        return view('livewire.storage.resources', [
            'groupedBackups' => $backups,
            'volumeBackups' => $volumeBackups,
            'allStorages' => $allStorages,
        ]);
    }
}
