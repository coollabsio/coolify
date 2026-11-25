<?php

namespace App\Livewire\Storage;

use App\Models\Application;
use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledVolumeBackup;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use Illuminate\Database\Eloquent\Builder;
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

        $backups = $this->backupsUsingStorage(ScheduledDatabaseBackup::query())
            ->where('save_s3', true)
            ->get();

        foreach ($backups as $backup) {
            $this->selectedStorages[$backup->id] = $this->storage->id;
        }

        $this->backupsUsingStorage(ScheduledVolumeBackup::query())
            ->where('save_s3', true)
            ->each(function (ScheduledVolumeBackup $backup): void {
                $this->selectedVolumeStorages[$backup->id] = $this->storage->id;
            });
    }

    public function disableS3(int $backupId): void
    {
        $this->authorize('update', $this->storage);

        $backup = $this->backupsUsingStorage(ScheduledDatabaseBackup::query())
            ->whereKey($backupId)
            ->firstOrFail();

        $changedFields = $this->replaceStorage($backup, null);

        $this->auditDatabaseBackupUpdated($backup, $changedFields);
        unset($this->selectedStorages[$backupId]);

        $this->dispatch('success', 'Storage removed.', $backup->save_s3
            ? 'This storage was removed from the schedule destinations.'
            : 'S3 backup has been disabled for this schedule.');
    }

    public function moveBackup(int $backupId): void
    {
        $this->authorize('update', $this->storage);

        $backup = $this->backupsUsingStorage(ScheduledDatabaseBackup::query())
            ->whereKey($backupId)
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

        $changedFields = $this->replaceStorage($backup, $newStorage->id);

        $this->auditDatabaseBackupUpdated($backup, $changedFields);
        unset($this->selectedStorages[$backupId]);

        $this->dispatch('success', 'Backup moved.', "Moved to {$newStorage->name}.");
    }

    public function disableVolumeS3(int $backupId): void
    {
        $this->authorize('update', $this->storage);

        $backup = $this->backupsUsingStorage(ScheduledVolumeBackup::query())
            ->whereKey($backupId)
            ->firstOrFail();

        $changedFields = $this->replaceStorage($backup, null);

        $this->auditVolumeBackupUpdated($backup, $changedFields);
        unset($this->selectedVolumeStorages[$backupId]);

        $this->dispatch('success', 'Storage removed.', $backup->save_s3
            ? 'This storage was removed from the schedule destinations.'
            : 'S3 backup has been disabled for this schedule.');
    }

    public function moveVolumeBackup(int $backupId): void
    {
        $this->authorize('update', $this->storage);

        $backup = $this->backupsUsingStorage(ScheduledVolumeBackup::query())
            ->whereKey($backupId)
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

        $changedFields = $this->replaceStorage($backup, $newStorage->id);

        $this->auditVolumeBackupUpdated($backup, $changedFields);
        unset($this->selectedVolumeStorages[$backupId]);

        $this->dispatch('success', 'Backup moved.', "Moved to {$newStorage->name}.");
    }

    /**
     * Schedules that have this storage among their destinations.
     *
     * @template TModel of ScheduledDatabaseBackup|ScheduledVolumeBackup
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function backupsUsingStorage(Builder $query): Builder
    {
        return $query->usingS3Storage($this->storage->id);
    }

    /**
     * Replaces this storage in the destinations of a schedule, or removes it when no replacement is given. S3 backups
     * turn off when no destination remains.
     *
     * @return array<int, string> Names of the changed backup fields.
     */
    private function replaceStorage(ScheduledDatabaseBackup|ScheduledVolumeBackup $backup, ?int $newStorageId): array
    {
        $storageIds = $backup->selectedS3Storages()
            ->pluck('id')
            ->map(fn (int $id): ?int => $id === $this->storage->id ? $newStorageId : $id)
            ->filter()
            ->unique()
            ->values();
        $previousPrimaryId = $backup->s3_storage_id;

        $backup->syncS3Storages($storageIds->all());

        $changedFields = ['s3_storages'];
        if ($backup->s3_storage_id !== $previousPrimaryId) {
            $changedFields[] = 's3_storage_id';
        }
        if ($storageIds->isEmpty() && $backup->save_s3) {
            $backup->update(['save_s3' => false]);
            $changedFields[] = 'save_s3';
        }

        return $changedFields;
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
        $backups = $this->backupsUsingStorage(ScheduledDatabaseBackup::query())
            ->where('save_s3', true)
            ->with('database')
            ->get()
            ->groupBy(fn ($backup) => $backup->database_type.'-'.$backup->database_id);

        $allStorages = S3Storage::where('team_id', $this->storage->team_id)
            ->orderBy('name')
            ->get(['id', 'name', 'is_usable']);

        $volumeBackups = $this->backupsUsingStorage(ScheduledVolumeBackup::query())
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
