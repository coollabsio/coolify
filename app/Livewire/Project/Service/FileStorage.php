<?php

namespace App\Livewire\Project\Service;

use App\Models\Application;
use App\Models\LocalFileVolume;
use App\Models\ScheduledVolumeBackup;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;

class FileStorage extends Component
{
    use AuthorizesRequests;

    public LocalFileVolume $fileStorage;

    public ServiceApplication|StandaloneRedis|StandalonePostgresql|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse|ServiceDatabase|Application $resource;

    public string $fs_path;

    public ?string $workdir = null;

    public bool $permanently_delete = true;

    public bool $isReadOnly = false;

    public bool $hasEnabledBackup = false;

    public ?string $backupUrl = null;

    #[Validate(['nullable'])]
    public ?string $content = null;

    #[Validate(['required', 'boolean'])]
    public bool $isBasedOnGit = false;

    #[Validate(['required', 'boolean'])]
    public bool $isPreviewSuffixEnabled = true;

    protected $rules = [
        'fileStorage.is_directory' => 'required',
        'fileStorage.fs_path' => 'required',
        'fileStorage.mount_path' => 'required',
        'content' => 'nullable',
        'isBasedOnGit' => 'required|boolean',
        'isPreviewSuffixEnabled' => 'required|boolean',
    ];

    public function mount()
    {
        $this->resource = $this->fileStorage->service;
        if (str($this->fileStorage->fs_path)->startsWith('.')) {
            $this->workdir = $this->resource->service?->workdir();
            $this->fs_path = str($this->fileStorage->fs_path)->after('.');
        } else {
            $this->workdir = null;
            $this->fs_path = $this->fileStorage->fs_path;
        }

        $this->isReadOnly = $this->fileStorage->shouldBeReadOnlyInUI() || $this->fileStorage->is_too_large;
        $this->syncData();
        $this->refreshBackupStatus();
    }

    #[On('refreshVolumeBackups')]
    public function refreshBackupStatus(): void
    {
        $backup = $this->fileStorage->is_directory
            ? $this->fileStorage->scheduledBackups()->first()
            : null;

        $this->hasEnabledBackup = $backup?->enabled ?? false;
        $this->backupUrl = null;

        if (! $this->hasEnabledBackup) {
            return;
        }

        if ($this->resource instanceof ServiceDatabase) {
            $this->backupUrl = route('project.service.database.backups', [
                'project_uuid' => $this->resource->service->project()->uuid,
                'environment_uuid' => $this->resource->service->environment->uuid,
                'service_uuid' => $this->resource->service->uuid,
                'stack_service_uuid' => $this->resource->uuid,
            ]);

            return;
        }

        if (! $this->resource instanceof Application) {
            $this->hasEnabledBackup = false;

            return;
        }

        $parameters = [
            'project_uuid' => $this->resource->project()->uuid,
            'environment_uuid' => $this->resource->environment->uuid,
            'application_uuid' => $this->resource->uuid,
        ];
        $hasOtherBackups = ScheduledVolumeBackup::query()
            ->forApplication($this->resource)
            ->where('id', '!=', $backup->id)
            ->exists();

        $this->backupUrl = $hasOtherBackups
            ? route('project.application.backup.index', [...$parameters, 'search' => $this->fileStorage->fs_path])
            : route('project.application.backup.show', [...$parameters, 'backup_uuid' => $backup->uuid]);
    }

    public function syncData(bool $toModel = false): void
    {
        if ($toModel) {
            if ($this->fileStorage->is_too_large) {
                return;
            }
            $this->validate();

            // Sync to model
            $this->fileStorage->content = $this->content;
            $this->fileStorage->is_based_on_git = $this->isBasedOnGit;
            $this->fileStorage->is_preview_suffix_enabled = $this->isPreviewSuffixEnabled;

            $this->fileStorage->save();
        } else {
            // Sync from model
            $this->content = $this->fileStorage->content;
            $this->isBasedOnGit = $this->fileStorage->is_based_on_git;
            $this->isPreviewSuffixEnabled = $this->fileStorage->is_preview_suffix_enabled ?? true;
        }
    }

    public function convertToDirectory()
    {
        try {
            $this->authorize('update', $this->resource);

            if ($this->fileStorage->is_host_file) {
                throw new \Exception('Host file mounts are bind-only and cannot be converted.');
            }

            $this->fileStorage->deleteStorageOnServer();
            $this->fileStorage->is_directory = true;
            $this->fileStorage->content = null;
            $this->fileStorage->is_based_on_git = false;
            $this->fileStorage->save();
            $this->fileStorage->saveStorageOnServer();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        } finally {
            $this->dispatch('storageCountsChanged')->to(Storage::class);
        }
    }

    public function loadStorageOnServer()
    {
        try {
            $this->authorize('update', $this->resource);

            if ($this->fileStorage->is_host_file) {
                throw new \Exception('Host file mounts are bind-only and cannot be loaded from the server.');
            }

            $this->fileStorage->loadStorageOnServer();
            $this->syncData();
            $this->dispatch('success', 'File storage loaded from server.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        } finally {
            $this->dispatch('storageCountsChanged')->to(Storage::class);
        }
    }

    public function convertToFile()
    {
        try {
            $this->authorize('update', $this->resource);

            if ($this->fileStorage->scheduledBackups()->exists()) {
                throw new \RuntimeException('Delete this directory backup schedule and its archives before converting it to a file.');
            }

            if ($this->fileStorage->is_host_file) {
                throw new \Exception('Host file mounts are bind-only and cannot be converted.');
            }

            $this->fileStorage->deleteStorageOnServer();
            $this->fileStorage->is_directory = false;
            $this->fileStorage->content = null;
            if (data_get($this->resource, 'settings.is_preserve_repository_enabled')) {
                $this->fileStorage->is_based_on_git = true;
            }
            $this->fileStorage->save();
            $this->fileStorage->saveStorageOnServer();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        } finally {
            $this->dispatch('storageCountsChanged')->to(Storage::class);
        }
    }

    public function delete($password, $selectedActions = [])
    {
        $this->authorize('update', $this->resource);

        if (! verifyPasswordConfirmation($password, $this)) {
            return 'The provided password is incorrect.';
        }

        if ($this->fileStorage->scheduledBackups()->exists()) {
            $this->dispatch('error', 'Delete this directory backup schedule and its archives before deleting the directory.');

            return false;
        }

        try {
            $message = 'File deleted.';
            if ($this->fileStorage->is_directory) {
                $message = 'Directory deleted.';
            } elseif ($this->fileStorage->is_host_file) {
                $message = 'Host file mount removed.';
            }
            if ($this->permanently_delete && ! $this->fileStorage->is_host_file) {
                $message = 'Directory deleted from the server.';
                $this->fileStorage->deleteStorageOnServer();
            }
            $this->fileStorage->delete();
            $this->dispatch('configurationChanged');
            $this->dispatch('success', $message);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        } finally {
            $this->dispatch('storageCountsChanged')->to(Storage::class);
        }

        return true;
    }

    public function submit()
    {
        $this->authorize('update', $this->resource);

        if ($this->fileStorage->is_host_file) {
            $this->dispatch('error', 'Host file mounts are bind-only and cannot be edited from the UI.');

            return;
        }

        if ($this->fileStorage->is_too_large) {
            $this->dispatch('error', 'File on server is too large to edit from the UI.');

            return;
        }

        $original = $this->fileStorage->getOriginal();
        try {
            $this->validate();
            if ($this->fileStorage->is_directory) {
                $this->content = null;
            }
            // Sync component properties to model
            $this->fileStorage->content = $this->content;
            $this->fileStorage->is_based_on_git = $this->isBasedOnGit;
            $this->fileStorage->is_preview_suffix_enabled = $this->isPreviewSuffixEnabled;
            $this->fileStorage->save();
            $this->fileStorage->saveStorageOnServer();
            $this->dispatch('success', 'File updated.');
        } catch (\Throwable $e) {
            $this->fileStorage->setRawAttributes($original);
            $this->fileStorage->save();
            $this->syncData();

            return handleError($e, $this);
        }
    }

    public function instantSave(): void
    {
        $this->authorize('update', $this->resource);
        if ($this->fileStorage->is_host_file) {
            $this->dispatch('error', 'Host file mounts are bind-only and cannot be edited from the UI.');

            return;
        }

        if ($this->fileStorage->is_too_large) {
            $this->dispatch('error', 'File on server is too large to edit from the UI.');

            return;
        }
        $this->syncData(true);
        $this->dispatch('success', 'File updated.');
    }

    public function render()
    {
        return view('livewire.project.service.file-storage', [
            'directoryDeletionCheckboxes' => [
                ['id' => 'permanently_delete', 'label' => 'The selected directory and all its contents will be permantely deleted form the server.'],
            ],
            'fileDeletionCheckboxes' => [
                ['id' => 'permanently_delete', 'label' => 'The selected file will be permanently deleted form the server.'],
            ],
            'hostFileDeletionCheckboxes' => [
                ['id' => 'permanently_delete', 'label' => 'Only the mount configuration will be removed. The host file will not be deleted.'],
            ],
        ]);
    }
}
