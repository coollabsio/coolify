<?php

namespace App\Livewire\Project\Service;

use App\Livewire\Concerns\AuditsStorageChanges;
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
use App\Models\StandaloneSqlite;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;

class FileStorage extends Component
{
    use AuditsStorageChanges;
    use AuthorizesRequests;

    public LocalFileVolume $fileStorage;

    public ServiceApplication|StandaloneRedis|StandalonePostgresql|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse|StandaloneSqlite|ServiceDatabase|Application $resource;

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

    /**
     * @return array<int, string> Names of the file storage fields the save changed.
     */
    private function syncData(bool $toModel = false): array
    {
        if ($toModel) {
            if ($this->fileStorage->is_too_large) {
                return [];
            }
            $this->validate();

            // Sync to model
            $this->fileStorage->content = $this->content;
            $this->fileStorage->is_based_on_git = $this->isBasedOnGit;
            $this->fileStorage->is_preview_suffix_enabled = $this->isPreviewSuffixEnabled;

            $changedFields = auditChangedFields($this->fileStorage);
            $this->fileStorage->save();

            return $changedFields;
        } else {
            // Sync from model
            $this->content = auth()->user()?->can('update', $this->resource) ? $this->fileStorage->content : null;
            $this->isBasedOnGit = $this->fileStorage->is_based_on_git;
            $this->isPreviewSuffixEnabled = $this->fileStorage->is_preview_suffix_enabled ?? true;
        }

        return [];
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
            $changedFields = auditChangedFields($this->fileStorage);
            $this->fileStorage->save();
            $this->fileStorage->saveStorageOnServer();
            $this->auditFileStorageUpdate($changedFields, 'converted_to_directory');
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
            $changedFields = auditChangedFields($this->fileStorage);
            $this->fileStorage->save();
            $this->fileStorage->saveStorageOnServer();
            $this->auditFileStorageUpdate($changedFields, 'converted_to_file');
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
            $deletedFromServer = $this->canDeleteFromServer() && in_array('permanently_delete', $selectedActions, true);
            if ($deletedFromServer) {
                $message = $this->fileStorage->is_directory ? 'Directory deleted from the server.' : 'File deleted from the server.';
                $this->fileStorage->deleteStorageOnServer();
            }
            $this->fileStorage->delete();
            $this->auditStorageChange($this->resource, 'deleted', $this->fileStorage, [
                'deleted_from_server' => $deletedFromServer,
            ]);
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
            $changedFields = auditChangedFields($this->fileStorage);
            $this->fileStorage->save();
            $this->fileStorage->saveStorageOnServer();
            $this->auditFileStorageUpdate($changedFields);
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
        $this->auditFileStorageUpdate($this->syncData(true));
        $this->dispatch('success', 'File updated.');
    }

    /**
     * @param  array<int, string>  $changedFields
     */
    private function auditFileStorageUpdate(array $changedFields, ?string $operation = null): void
    {
        if ($changedFields === []) {
            return;
        }

        $this->auditStorageChange($this->resource, 'updated', $this->fileStorage, array_filter([
            'changed_fields' => $changedFields,
            'operation' => $operation,
        ], fn ($value) => $value !== null));
    }

    /**
     * Only mounts inside the resource directory are ever deleted on the server.
     */
    private function canDeleteFromServer(): bool
    {
        return ! $this->fileStorage->is_host_file && ! $this->fileStorage->isOutsideResourceDirectory();
    }

    public function render()
    {
        $kind = $this->fileStorage->is_directory ? 'directory' : 'file';
        $deletionActions = [
            $this->fileStorage->is_host_file
                ? 'The mount will be removed from the container.'
                : "The selected {$kind} will be permanently deleted from the container.",
        ];
        $deletionCheckboxes = [];

        if ($this->canDeleteFromServer()) {
            $deletionCheckboxes[] = [
                'id' => 'permanently_delete',
                'label' => $this->fileStorage->is_directory
                    ? 'The selected directory and all its contents will be permanently deleted from the server.'
                    : 'The selected file will be permanently deleted from the server.',
            ];
        } else {
            $deletionActions[] = "Only the mount configuration will be removed. The {$kind} at {$this->fileStorage->fs_path} is not deleted on the server.";
        }

        return view('livewire.project.service.file-storage', [
            'deletionCheckboxes' => $deletionCheckboxes,
            'deletionActions' => $deletionActions,
        ]);
    }
}
