<?php

namespace App\Livewire\Project\Shared\Storages;

use App\Models\Application;
use App\Models\LocalPersistentVolume;
use App\Models\ScheduledVolumeBackup;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\On;
use Livewire\Component;

class Show extends Component
{
    use AuthorizesRequests;

    public LocalPersistentVolume $storage;

    public $resource;

    public bool $isReadOnly = false;

    public bool $isFirst = true;

    public bool $isService = false;

    public ?string $startedAt = null;

    public bool $supportsPreviewSuffix = false;

    // Explicit properties
    public string $name;

    public string $mountPath;

    public ?string $hostPath = null;

    public bool $isPreviewSuffixEnabled = true;

    public bool $hasEnabledBackup = false;

    public ?string $backupUrl = null;

    /**
     * When true, parent already batched badge/url data — skip per-row queries on mount.
     */
    public bool $backupMetaHydrated = false;

    /** When true, the Backup Configure Livewire modal is mounted (lazy). */
    public bool $showBackupModal = false;

    protected $validationAttributes = [
        'name' => 'name',
        'mountPath' => 'mount',
        'hostPath' => 'host',
    ];

    protected function rules(): array
    {
        return [
            'name' => ValidationPatterns::volumeNameRules(),
            'mountPath' => ['required', 'string', 'regex:'.ValidationPatterns::DIRECTORY_PATH_PATTERN],
            'hostPath' => ['nullable', 'string', 'regex:'.ValidationPatterns::DIRECTORY_PATH_PATTERN],
            'isPreviewSuffixEnabled' => 'required|boolean',
        ];
    }

    protected function messages(): array
    {
        return array_merge(
            ValidationPatterns::volumeNameMessages(),
            [
                'mountPath.regex' => 'Mount path must start with / and only contain safe path characters.',
                'hostPath.regex' => 'Host path must start with / and only contain safe path characters.',
            ]
        );
    }

    /**
     * Sync data between component properties and model
     *
     * @param  bool  $toModel  If true, sync FROM properties TO model. If false, sync FROM model TO properties.
     */
    private function syncData(bool $toModel = false): void
    {
        if ($toModel) {
            // Sync TO model (before save)
            $this->storage->name = $this->name;
            $this->storage->mount_path = $this->mountPath;
            $this->storage->host_path = $this->hostPath;
            $this->storage->is_preview_suffix_enabled = $this->isPreviewSuffixEnabled;
        } else {
            // Sync FROM model (on load/refresh)
            $this->name = $this->storage->name;
            $this->mountPath = $this->storage->mount_path;
            $this->hostPath = $this->storage->host_path;
            $this->isPreviewSuffixEnabled = $this->storage->is_preview_suffix_enabled ?? true;
        }
    }

    public function mount(): void
    {
        $this->syncData(false);
        $this->isReadOnly = $this->storage->shouldBeReadOnlyInUI();
        // PR deployment volume suffixes only apply to git-based applications.
        $this->supportsPreviewSuffix = $this->resource instanceof Application
            && $this->resource->git_based()
            && filled($this->resource->git_repository)
            && ! $this->isService;
        // Parent All batches badge/url; isolated embeds still hydrate themselves.
        if (! $this->backupMetaHydrated) {
            $this->refreshBackupStatus();
        }
    }

    #[On('refreshVolumeBackups')]
    public function refreshBackupStatus(): void
    {
        $backup = $this->storage->scheduledBackups()->first();

        $this->hasEnabledBackup = $backup?->enabled ?? false;
        $this->backupUrl = null;

        if (! $this->hasEnabledBackup || ! $this->resource instanceof Application) {
            return;
        }

        $this->resource->loadMissing('environment.project');

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
            ? route('project.application.backup.index', [...$parameters, 'search' => $this->storage->name])
            : route('project.application.backup.show', [...$parameters, 'backup_uuid' => $backup->uuid]);
    }

    public function openBackupModal(): void
    {
        $this->authorize('update', $this->resource);
        $this->showBackupModal = true;
    }

    #[On('modalClosed')]
    public function onModalClosed(): void
    {
        // Drop the nested Create component from the DOM after close to free snapshot weight.
        if ($this->showBackupModal) {
            $this->showBackupModal = false;
        }
    }

    public function instantSave(): void
    {
        $this->authorize('update', $this->resource);
        $this->validate();

        $this->syncData(true);
        $this->storage->save();
        $this->dispatch('success', 'Storage updated successfully');
    }

    public function submit()
    {
        $this->authorize('update', $this->resource);

        $this->validate();
        $this->syncData(true);
        $this->storage->save();
        $this->dispatch('success', 'Storage updated successfully');
    }

    public function delete($password, $selectedActions = [])
    {
        $this->authorize('update', $this->resource);

        if (! verifyPasswordConfirmation($password, $this)) {
            return 'The provided password is incorrect.';
        }

        if ($this->storage->scheduledBackups()->exists()) {
            $this->dispatch('error', 'Delete this volume backup schedule and its archives before deleting the volume.');

            return false;
        }

        $this->storage->delete();
        $this->dispatch('refreshStorages');
        $this->dispatch('configurationChanged');

        return true;
    }
}
