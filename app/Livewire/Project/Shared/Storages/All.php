<?php

namespace App\Livewire\Project\Shared\Storages;

use App\Models\Application;
use App\Models\LocalFileVolume;
use App\Models\LocalPersistentVolume;
use App\Models\ScheduledVolumeBackup;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class All extends Component
{
    use AuthorizesRequests;

    public $resource;

    /**
     * Editable form state keyed by storage id.
     *
     * @var array<int|string, array{name: string, mountPath: string, hostPath: ?string, isPreviewSuffixEnabled: bool, isReadOnly: bool, canDeleteStale: bool}>
     */
    public array $forms = [];

    /**
     * Precomputed per-volume backup badge/link data.
     *
     * @var array<int, array{enabled: bool, s3: bool, url: ?string}>
     */
    public array $volumeBackupMeta = [];

    public bool $supportsPreviewSuffix = false;

    public bool $showActionsColumn = false;

    public bool $showBackupAction = false;

    public bool $isComposeOrService = false;

    public bool $canUpdate = false;

    public bool $deleteDockerVolume = false;

    protected $listeners = ['refreshStorages' => 'refreshList', 'refreshVolumeBackups' => 'refreshList'];

    public function mount(): void
    {
        $this->canUpdate = (bool) auth()->user()?->can('update', $this->resource);
        $this->supportsPreviewSuffix = $this->resource instanceof Application
            && $this->resource->git_based()
            && filled($this->resource->git_repository);
        $this->showActionsColumn = $this->canUpdate;
        $this->showBackupAction = $this->resource instanceof Application
            || $this->resource instanceof ServiceApplication
            || $this->resource instanceof ServiceDatabase;
        $this->isComposeOrService = $this->resource->type() === 'service'
            || data_get($this->resource, 'build_pack') === 'dockercompose';

        $this->refreshList();
    }

    public function refreshList(): void
    {
        $this->resource->refresh();
        $this->resource->unsetRelation('persistentStorages');
        $this->resource->load(['persistentStorages' => fn ($query) => $query->orderBy('id')]);

        foreach ($this->resource->persistentStorages as $storage) {
            $storage->setRelation('resource', $this->resource);
        }

        if ($this->resource instanceof Application) {
            $this->resource->loadMissing('environment.project');
        }

        $this->rebuildForms();
        $this->rebuildVolumeBackupMeta();
    }

    public function submit(int $storageId): void
    {
        $this->authorize('update', $this->resource);
        $this->validateStorage($storageId);

        $storage = $this->findStorageOrFail($storageId);
        if ($storage->shouldBeReadOnlyInUI()) {
            $this->dispatch('error', 'This volume is read-only.');

            return;
        }

        $form = $this->forms[$storageId];
        $storage->name = $form['name'];
        $storage->mount_path = $form['mountPath'];
        $storage->host_path = $form['hostPath'] ?: null;
        $storage->is_preview_suffix_enabled = (bool) $form['isPreviewSuffixEnabled'];
        $storage->save();

        $this->dispatch('success', 'Storage updated successfully');
    }

    public function instantSave(int $storageId): void
    {
        $this->submit($storageId);
    }

    /**
     * Livewire listbox onChange cannot pass args; PR suffix fields call this via updatedForms.
     */
    public function updatedForms($value, string $key): void
    {
        if (! str_ends_with($key, '.isPreviewSuffixEnabled')) {
            return;
        }

        $storageId = (int) explode('.', $key)[0];
        if ($storageId > 0 && isset($this->forms[$storageId]) && ! $this->forms[$storageId]['isReadOnly']) {
            $this->instantSave($storageId);
        }
    }

    public function delete(int $storageId, $password = '', $selectedActions = [])
    {
        $this->authorize('update', $this->resource);

        if (! verifyPasswordConfirmation($password, $this)) {
            return 'The provided password is incorrect.';
        }

        $storage = $this->findStorageOrFail($storageId);

        if ($this->isComposeOrService && $storage->isDeclaredInCompose()) {
            $this->dispatch('error', 'This volume is managed by the current Docker Compose file.');

            return false;
        }

        if ($storage->scheduledBackups()->exists()) {
            $this->dispatch('error', 'Delete this volume backup schedule and its archives before deleting the volume.');

            return false;
        }

        $this->deleteDockerVolume = in_array('deleteDockerVolume', $selectedActions, true);
        if ($this->deleteDockerVolume) {
            $server = $this->resource instanceof Application
                ? $this->resource->destination->server
                : $this->resource->service->server;

            try {
                instant_remote_process([
                    'docker volume rm -f '.escapeshellarg($storage->name),
                ], $server);
            } catch (\Throwable $exception) {
                $this->dispatch('error', 'Failed to delete the Docker volume: '.$exception->getMessage());

                return false;
            }
        }

        $storage->delete();
        $this->refreshList();
        $this->dispatch('refreshStorages');
        $this->dispatch('configurationChanged');

        return true;
    }

    public function render()
    {
        return view('livewire.project.shared.storages.all');
    }

    /**
     * @return array<int, LocalPersistentVolume>
     */
    public function getStoragesProperty(): array
    {
        return $this->resource->persistentStorages
            ->sortBy('id')
            ->values()
            ->all();
    }

    private function rebuildForms(): void
    {
        $forms = [];
        foreach ($this->resource->persistentStorages->sortBy('id') as $storage) {
            $forms[$storage->id] = [
                'name' => $storage->name,
                'mountPath' => $storage->mount_path,
                'hostPath' => $storage->host_path,
                'isPreviewSuffixEnabled' => (bool) ($storage->is_preview_suffix_enabled ?? true),
                'isReadOnly' => $storage->shouldBeReadOnlyInUI() || ! $this->canUpdate,
                'canDeleteStale' => $this->canUpdate
                    && ($storage->isServiceResource() || $storage->isDockerComposeResource())
                    && ! $storage->isDeclaredInCompose(),
            ];
        }
        $this->forms = $forms;
    }

    private function rebuildVolumeBackupMeta(): void
    {
        $this->volumeBackupMeta = [];

        if (! $this->showBackupAction) {
            return;
        }

        $storages = $this->resource->persistentStorages;
        if ($storages->isEmpty()) {
            return;
        }

        $volumeMorph = (new LocalPersistentVolume)->getMorphClass();
        $directoryMorph = (new LocalFileVolume)->getMorphClass();
        $volumeIds = $storages->pluck('id');

        $volumeBackups = ScheduledVolumeBackup::query()
            ->where('backupable_type', $volumeMorph)
            ->whereIn('backupable_id', $volumeIds)
            ->get()
            ->keyBy('backupable_id');

        $directoryIds = LocalFileVolume::query()
            ->where('resource_id', $this->resource->id)
            ->where('resource_type', $this->resource->getMorphClass())
            ->where('is_directory', true)
            ->where('is_host_file', false)
            ->pluck('id');

        $totalResourceBackups = ScheduledVolumeBackup::query()
            ->where(function ($query) use ($volumeMorph, $volumeIds, $directoryMorph, $directoryIds): void {
                $query->where(function ($query) use ($volumeMorph, $volumeIds): void {
                    $query->where('backupable_type', $volumeMorph)
                        ->whereIn('backupable_id', $volumeIds);
                })->orWhere(function ($query) use ($directoryMorph, $directoryIds): void {
                    $query->where('backupable_type', $directoryMorph)
                        ->whereIn('backupable_id', $directoryIds);
                });
            })
            ->count();

        $service = $this->resource instanceof Application ? null : $this->resource->service;
        $parameters = $service ? [
            'project_uuid' => $service->project()->uuid,
            'environment_uuid' => $service->environment->uuid,
            'service_uuid' => $service->uuid,
        ] : [
            'project_uuid' => $this->resource->project()->uuid,
            'environment_uuid' => $this->resource->environment->uuid,
            'application_uuid' => $this->resource->uuid,
        ];

        foreach ($storages as $storage) {
            $backup = $volumeBackups->get($storage->id);
            $enabled = (bool) ($backup?->enabled);
            $url = null;

            if ($enabled && $backup) {
                $routePrefix = $service ? 'project.service.volume-backups' : 'project.application.backup';
                $url = $totalResourceBackups > 1
                    ? route($routePrefix.'.index', [...$parameters, 'search' => $storage->name])
                    : route($routePrefix.'.show', [...$parameters, 'backup_uuid' => $backup->uuid]);
            }

            $this->volumeBackupMeta[(int) $storage->id] = [
                'enabled' => $enabled,
                's3' => $enabled && (bool) $backup?->save_s3,
                'url' => $url,
            ];
        }
    }

    private function validateStorage(int $storageId): void
    {
        $this->validate([
            "forms.{$storageId}.name" => ValidationPatterns::volumeNameRules(),
            "forms.{$storageId}.mountPath" => ['required', 'string', 'regex:'.ValidationPatterns::DIRECTORY_PATH_PATTERN],
            "forms.{$storageId}.hostPath" => ['nullable', 'string', 'regex:'.ValidationPatterns::DIRECTORY_PATH_PATTERN],
            "forms.{$storageId}.isPreviewSuffixEnabled" => 'required|boolean',
        ], array_merge(
            ValidationPatterns::volumeNameMessages(),
            [
                "forms.{$storageId}.mountPath.regex" => 'Mount path must start with / and only contain safe path characters.',
                "forms.{$storageId}.hostPath.regex" => 'Host path must start with / and only contain safe path characters.',
            ]
        ), [
            "forms.{$storageId}.name" => 'name',
            "forms.{$storageId}.mountPath" => 'mount',
            "forms.{$storageId}.hostPath" => 'host',
        ]);
    }

    private function findStorageOrFail(int $storageId): LocalPersistentVolume
    {
        $storage = $this->resource->persistentStorages->firstWhere('id', $storageId);
        if (! $storage) {
            $storage = LocalPersistentVolume::query()
                ->whereKey($storageId)
                ->where('resource_id', $this->resource->id)
                ->where('resource_type', $this->resource->getMorphClass())
                ->firstOrFail();
            $storage->setRelation('resource', $this->resource);
        }

        return $storage;
    }
}
