<?php

namespace App\Livewire\Storage;

use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use Livewire\Component;

class Resources extends Component
{
    public S3Storage $storage;

    public array $selectedStorages = [];

    public function mount(): void
    {
        $backups = ScheduledDatabaseBackup::where('s3_storage_id', $this->storage->id)
            ->where('save_s3', true)
            ->get();

        foreach ($backups as $backup) {
            $this->selectedStorages[$backup->id] = $this->storage->id;
        }
    }

    public function disableS3(int $backupId): void
    {
        $backup = ScheduledDatabaseBackup::findOrFail($backupId);

        $backup->update([
            'save_s3' => false,
            's3_storage_id' => null,
        ]);

        unset($this->selectedStorages[$backupId]);

        $this->dispatch('success', 'S3 disabled.', 'S3 backup has been disabled for this schedule.');
    }

    public function moveBackup(int $backupId): void
    {
        $backup = ScheduledDatabaseBackup::findOrFail($backupId);
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

        $backup->update(['s3_storage_id' => $newStorage->id]);

        unset($this->selectedStorages[$backupId]);

        $this->dispatch('success', 'Backup moved.', "Moved to {$newStorage->name}.");
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

        return view('livewire.storage.resources', [
            'groupedBackups' => $backups,
            'allStorages' => $allStorages,
        ]);
    }
}
