<?php

namespace App\Livewire\Project\Shared\Storages;

use App\Livewire\Concerns\AuditsStorageChanges;
use App\Livewire\Project\Service\Storage as StorageComponent;
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
    use AuditsStorageChanges;
    use AuthorizesRequests;

    public $resource;

    /**
     * Editable form state keyed by storage id.
     *
     * @var array<int|string, array{name: string, mountPath: string, savedName: string, savedMountPath: string, isPreviewSuffixEnabled: bool, isReadOnly: bool, isShared: bool, isExternal: bool, canDeleteStale: bool, replacedExternalVolume: ?string, replacedHostPath: ?string, ignoresDriverOptions: bool, canDeleteToApplyDriverOptions: bool, usedByContainers: list<string>}>
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

    /**
     * Volume rename that waits for confirmation. A new name mounts another Docker volume, and the data stays in the old one.
     *
     * @var array{storageId: int, from: string, to: string}|null
     */
    public ?array $pendingRename = null;

    protected $listeners = ['refreshVolumeList' => 'refreshList', 'refreshVolumeBackups' => 'refreshList'];

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
        $this->authorize('view', $this->resource);
        $this->resource->refresh();
        $this->resource->unsetRelation('persistentStorages');
        $this->resource->load(['persistentStorages' => fn ($query) => $query->with('standaloneSqlite')->orderBy('id')]);

        foreach ($this->resource->persistentStorages as $storage) {
            $storage->setRelation('resource', $this->resource);
        }

        if ($this->resource instanceof Application) {
            $this->resource->loadMissing('environment.project');
        }

        $this->pendingRename = null;
        $this->rebuildForms();
        $this->rebuildStaleVolumeContainers();
        $this->rebuildVolumeBackupMeta();
    }

    /**
     * Saves the name and mount path of one storage, or of every storage with unsaved changes when
     * no storage is given (the unsaved changes bar). A rename stops the save until the user confirms it.
     */
    public function submit(?int $storageId = null, bool $renameConfirmed = false): void
    {
        $this->authorize('update', $this->resource);

        $storageIds = $storageId !== null ? [$storageId] : $this->changedStorageIds();
        foreach ($storageIds as $id) {
            if (! $this->saveStorage($id, $renameConfirmed)) {
                return;
            }
        }

        if ($storageIds !== []) {
            $this->dispatch('success', 'Storage updated successfully');
        }
    }

    /**
     * @return list<int>
     */
    private function changedStorageIds(): array
    {
        return $this->resource->persistentStorages
            ->filter(fn (LocalPersistentVolume $storage): bool => isset($this->forms[$storage->id])
                && ! $this->forms[$storage->id]['isReadOnly']
                && ($this->forms[$storage->id]['name'] !== $storage->name
                    || $this->forms[$storage->id]['mountPath'] !== $storage->mount_path))
            ->pluck('id')
            ->all();
    }

    /**
     * Returns false when the storage is not saved: it is read-only, or a rename waits for confirmation.
     */
    private function saveStorage(int $storageId, bool $renameConfirmed): bool
    {
        $this->validateStorage($storageId);

        $storage = $this->findStorageOrFail($storageId);
        if ($storage->shouldBeReadOnlyInUI()) {
            $this->dispatch('error', 'This volume is read-only.');

            return false;
        }

        $form = $this->forms[$storageId];
        $isRename = ! $storage->is_external && ! $storage->isSharedWithAnotherResource() && $form['name'] !== $storage->name;
        $rename = ['storageId' => $storageId, 'from' => $storage->name, 'to' => $form['name']];
        if ($isRename && ! ($renameConfirmed && $this->pendingRename === $rename)) {
            $this->pendingRename = $rename;

            return false;
        }
        $this->pendingRename = null;

        if ($isRename) {
            $storage->name = $form['name'];
        }
        $storage->mount_path = $form['mountPath'];
        $changedFields = auditChangedFields($storage);
        $storage->save();

        if ($changedFields !== []) {
            $this->auditStorageChange($this->resource, 'updated', $storage, ['changed_fields' => $changedFields]);
        }
        $this->forms[$storageId]['name'] = $storage->name;
        $this->forms[$storageId]['savedName'] = $storage->name;
        $this->forms[$storageId]['savedMountPath'] = $storage->mount_path;

        return true;
    }

    public function cancelRename(int $storageId): void
    {
        $this->authorize('view', $this->resource);
        $storage = $this->findStorageOrFail($storageId);
        $this->forms[$storageId]['name'] = $storage->name;
        $this->pendingRename = null;
    }

    /**
     * Saves only the PR suffix setting, so that unsaved name or mount path edits stay unsaved.
     */
    public function instantSave(int $storageId): void
    {
        $this->authorize('update', $this->resource);
        $this->validate([
            "forms.{$storageId}.isPreviewSuffixEnabled" => 'required|boolean',
        ]);

        // The PR suffix is a Coolify setting, so it can change also on a volume that is read-only here.
        $storage = $this->findStorageOrFail($storageId);
        $storage->is_preview_suffix_enabled = (bool) $this->forms[$storageId]['isPreviewSuffixEnabled'];
        $changedFields = auditChangedFields($storage);
        $storage->save();

        if ($changedFields !== []) {
            $this->auditStorageChange($this->resource, 'updated', $storage, ['changed_fields' => $changedFields]);
        }

        $this->dispatch('success', 'Storage updated successfully');
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
        if ($storageId > 0 && isset($this->forms[$storageId]) && $this->canUpdate) {
            $this->instantSave($storageId);
        }
    }

    public function delete(int $storageId, $password = '', $selectedActions = [])
    {
        $this->authorize('delete', $this->resource);

        if (! verifyPasswordConfirmation($password, $this)) {
            return 'The provided password is incorrect.';
        }

        $storage = $this->findStorageOrFail($storageId);

        if ($storage->isSharedWithAnotherResource()) {
            $this->dispatch('error', 'This volume is connected to a SQLite database. Unlink it on the SQLite database page.');

            return false;
        }

        if ($this->isComposeOrService && $storage->isDeclaredInCompose() && ! $storage->ignoresComposeDriverOptionsOfDeclaration()) {
            $this->dispatch('error', 'This volume is managed by the current Docker Compose file.');

            return false;
        }

        if ($storage->scheduledBackups()->exists()) {
            $this->dispatch('error', 'Delete this volume backup schedule and its archives before deleting the volume.');

            return false;
        }

        $this->deleteDockerVolume = ! $storage->is_external && in_array('deleteDockerVolume', $selectedActions, true);
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
        $this->auditStorageChange($this->resource, 'deleted', $storage, [
            'docker_volume_deleted' => $this->deleteDockerVolume,
        ]);
        $this->refreshList();
        $this->dispatch('storageCountsChanged')->to(StorageComponent::class);
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
            $ignoresDriverOptions = $this->isComposeOrService && $storage->ignoresComposeDriverOptionsOfDeclaration();
            $forms[$storage->id] = [
                'name' => $storage->name,
                'mountPath' => $storage->mount_path,
                'savedName' => $storage->name,
                'savedMountPath' => $storage->mount_path,
                'isPreviewSuffixEnabled' => (bool) ($storage->is_preview_suffix_enabled ?? true),
                'isReadOnly' => $storage->shouldBeReadOnlyInUI() || ! $this->canUpdate,
                'isShared' => $storage->isSharedWithAnotherResource(),
                'isExternal' => $storage->is_external,
                'canDeleteStale' => $this->canUpdate
                    && ($storage->isServiceResource() || $storage->isDockerComposeResource())
                    && ! $ignoresDriverOptions
                    && ! $storage->isDeclaredInCompose(),
                'canDeleteToApplyDriverOptions' => $this->canUpdate && $ignoresDriverOptions,
                'replacedExternalVolume' => $this->isComposeOrService ? $storage->replacedExternalComposeVolume() : null,
                'replacedHostPath' => $this->isComposeOrService ? $storage->replacedComposeHostPath() : null,
                'ignoresDriverOptions' => $ignoresDriverOptions,
                'usedByContainers' => [],
            ];
        }
        $this->forms = $forms;
    }

    /**
     * Finds the containers that still use a stale volume. Docker Compose can keep such a volume
     * in a container after the line was removed from the Compose file. After the entry is
     * deleted, the next deployment starts that container without the data (#6875).
     */
    private function rebuildStaleVolumeContainers(): void
    {
        $staleNames = collect($this->forms)->filter(fn (array $form) => $form['canDeleteStale'])->pluck('name');
        if ($staleNames->isEmpty()) {
            return;
        }
        $server = $this->resource instanceof Application
            ? $this->resource->destination->server
            : $this->resource->service->server;
        try {
            $output = (string) instant_remote_process([remoteCommandWithTimeout("docker ps -a --no-trunc --format '{{.Names}} {{.Mounts}}'", 30)], $server, false, timeout: 45, maxAttempts: 1);
        } catch (\Throwable) {
            return;
        }
        $containers = collect(preg_split('/\R/', trim($output)))->filter()->mapWithKeys(function (string $line) {
            [$name, $mounts] = array_pad(explode(' ', $line, 2), 2, '');

            return [$name => explode(',', $mounts)];
        });
        foreach ($this->forms as $id => $form) {
            if ($form['canDeleteStale']) {
                $this->forms[$id]['usedByContainers'] = $containers
                    ->filter(fn (array $mounts) => in_array($form['name'], $mounts, true))
                    ->keys()
                    ->values()
                    ->all();
            }
        }
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
            "forms.{$storageId}.isPreviewSuffixEnabled" => 'required|boolean',
        ], array_merge(
            ValidationPatterns::volumeNameMessages(),
            [
                "forms.{$storageId}.mountPath.regex" => 'Mount path must start with / and only contain safe path characters.',
            ]
        ), [
            "forms.{$storageId}.name" => 'name',
            "forms.{$storageId}.mountPath" => 'mount',
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
