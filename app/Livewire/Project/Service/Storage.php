<?php

namespace App\Livewire\Project\Service;

use App\Livewire\Project\Shared\Storages\All as StorageList;
use App\Models\Application;
use App\Models\LocalFileVolume;
use App\Models\LocalPersistentVolume;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Storage extends Component
{
    use AuthorizesRequests;

    public $resource;

    public $fileStorage;

    public $isSwarm = false;

    public string $name = '';

    public string $mount_path = '';

    public ?string $host_path = null;

    public string $file_storage_path = '';

    public ?string $file_storage_content = null;

    public string $host_file_storage_source = '';

    public string $host_file_storage_destination = '';

    public string $file_storage_directory_source = '';

    public string $file_storage_directory_destination = '';

    public string $activeTab = 'volumes';

    public int $cachedVolumeCount = 0;

    public int $cachedFileCount = 0;

    public int $cachedDirectoryCount = 0;

    public function getListeners()
    {
        $teamId = auth()->user()->currentTeam()->id;

        return [
            "echo-private:team.{$teamId},FileStorageChanged" => 'refreshStoragesFromEvent',
            'storageCountsChanged' => 'refreshStorages',
            'addNewVolume',
        ];
    }

    public function mount()
    {
        if (str($this->resource->getMorphClass())->contains('Standalone')) {
            $this->file_storage_directory_source = database_configuration_dir()."/{$this->resource->uuid}";
        } else {
            $this->file_storage_directory_source = application_configuration_dir()."/{$this->resource->uuid}";
        }

        if ($this->resource->getMorphClass() === Application::class) {
            $this->resource->loadMissing('destination.server', 'environment.project');
            if ($this->resource->destination?->server?->isSwarm()) {
                $this->isSwarm = true;
            }
        }

        // Counts only on mount — child All (volumes) / file list load their own payloads.
        $this->loadVolumeCount();
        $this->loadFileStorageMetaCounts();
        $this->activeTab = $this->resolveDefaultTab();
        $this->fileStorage = collect();
        $this->loadFileStorageForActiveTab();
    }

    public function refreshStoragesFromEvent()
    {
        $this->refreshStorages();
        $this->dispatch('warning', 'File storage changed. Usually it means that the file / directory is already defined on the server, so Coolify set it up for you properly on the UI.');
    }

    public function refreshStorages()
    {
        $hadVolumes = $this->cachedVolumeCount > 0;

        // Avoid loading full volume models onto this parent (child All owns that snapshot).
        $this->resource->unsetRelation('persistentStorages');
        $this->loadVolumeCount();
        $this->loadFileStorageMetaCounts();
        $this->loadFileStorageForActiveTab();

        if ($this->activeTab === 'volumes' && $hadVolumes && $this->cachedVolumeCount > 0) {
            $this->dispatch('refreshVolumeList')->to(StorageList::class);
        }
    }

    public function setActiveTab(string $tab): void
    {
        if (! in_array($tab, ['volumes', 'files', 'directories'], true)) {
            return;
        }

        $this->activeTab = $tab;
        $this->loadFileStorageForActiveTab();
    }

    private function resolveDefaultTab(): string
    {
        if ($this->volumeCount > 0) {
            return 'volumes';
        }

        if ($this->fileCount > 0) {
            return 'files';
        }

        if ($this->directoryCount > 0) {
            return 'directories';
        }

        return 'volumes';
    }

    private function loadVolumeCount(): void
    {
        $this->cachedVolumeCount = $this->resource->persistentStorages()->count();
    }

    /**
     * Counts only — avoids loading file contents into the Livewire snapshot on the volumes tab.
     */
    private function loadFileStorageMetaCounts(): void
    {
        $this->cachedFileCount = $this->resource->fileStorages()->where('is_directory', false)->count();
        $this->cachedDirectoryCount = $this->resource->fileStorages()->where('is_directory', true)->count();
    }

    /**
     * Load full file/directory mounts only for the active tab (content only on files).
     */
    private function loadFileStorageForActiveTab(): void
    {
        if ($this->activeTab === 'volumes') {
            // Keep snapshot small while the volumes tab is shown.
            $this->fileStorage = collect();

            return;
        }

        $query = $this->resource->fileStorages();

        if ($this->activeTab === 'files') {
            $query->where('is_directory', false);
        } else {
            $query->where('is_directory', true);
        }

        $this->fileStorage = $query->get()->each(function (LocalFileVolume $fs): void {
            if ($this->activeTab !== 'files') {
                $fs->content = null;

                return;
            }

            if (strlen((string) $fs->content) > LocalFileVolume::MAX_CONTENT_SIZE) {
                $fs->content = LocalFileVolume::TOO_LARGE_PLACEHOLDER;
            }
        });
    }

    public function getFilesProperty()
    {
        return collect($this->fileStorage)->where('is_directory', false);
    }

    public function getDirectoriesProperty()
    {
        return collect($this->fileStorage)->where('is_directory', true);
    }

    public function getVolumeCountProperty()
    {
        return $this->cachedVolumeCount;
    }

    public function getFileCountProperty()
    {
        return $this->cachedFileCount;
    }

    public function getDirectoryCountProperty()
    {
        return $this->cachedDirectoryCount;
    }

    public function submitPersistentVolume()
    {
        try {
            $this->authorize('update', $this->resource);

            $this->validate([
                'name' => ValidationPatterns::volumeNameRules(),
                'mount_path' => 'required|string',
                'host_path' => $this->isSwarm
                    ? ['required', 'string', 'regex:'.ValidationPatterns::DIRECTORY_PATH_PATTERN]
                    : ['nullable', 'string', 'regex:'.ValidationPatterns::DIRECTORY_PATH_PATTERN],
            ], array_merge(ValidationPatterns::volumeNameMessages(), [
                'host_path.regex' => 'Host path must start with / and only contain safe path characters.',
            ]));

            $name = $this->resource->uuid.'-'.$this->name;

            LocalPersistentVolume::create([
                'name' => $name,
                'mount_path' => $this->mount_path,
                'host_path' => $this->host_path,
                'resource_id' => $this->resource->id,
                'resource_type' => $this->resource->getMorphClass(),
            ]);
            $this->clearForm();
            $this->activeTab = 'volumes';
            $this->refreshStorages();
            $this->dispatch('configurationChanged');
            $this->dispatch('success', 'Volume added successfully');
            $this->dispatch('closeStorageModal', 'volume');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function submitFileStorage()
    {
        try {
            $this->authorize('update', $this->resource);

            $this->validate([
                'file_storage_path' => 'required|string',
                'file_storage_content' => 'nullable|string',
            ]);

            $this->file_storage_path = validateFileMountPath($this->file_storage_path, 'file storage path');

            $fs_path = confineFileMountPath($this->fileStorageHostPath(), $this->file_storage_path, 'file storage path');

            LocalFileVolume::create([
                'fs_path' => $fs_path,
                'mount_path' => $this->file_storage_path,
                'content' => $this->file_storage_content,
                'is_directory' => false,
                'resource_id' => $this->resource->id,
                'resource_type' => get_class($this->resource),
            ]);

            $this->clearForm();
            $this->activeTab = 'files';
            $this->refreshStorages();
            $this->dispatch('configurationChanged');
            $this->dispatch('success', 'File mount added successfully');
            $this->dispatch('closeStorageModal', 'file');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function submitHostFileStorage()
    {
        try {
            $this->authorize('update', $this->resource);

            $this->validate([
                'host_file_storage_source' => 'required|string',
                'host_file_storage_destination' => 'required|string',
            ]);

            $this->host_file_storage_source = validateHostFileMountPath($this->host_file_storage_source, 'host file source path');
            $this->host_file_storage_destination = validateFileMountPath($this->host_file_storage_destination, 'host file destination path');

            LocalFileVolume::create([
                'fs_path' => $this->host_file_storage_source,
                'mount_path' => $this->host_file_storage_destination,
                'content' => null,
                'is_directory' => false,
                'is_host_file' => true,
                'resource_id' => $this->resource->id,
                'resource_type' => get_class($this->resource),
            ]);

            $this->clearForm();
            $this->activeTab = 'files';
            $this->refreshStorages();
            $this->dispatch('configurationChanged');
            $this->dispatch('success', 'Host file mount added successfully');
            $this->dispatch('closeStorageModal', 'host-file');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function submitFileStorageDirectory()
    {
        try {
            $this->authorize('update', $this->resource);

            $this->validate([
                'file_storage_directory_source' => 'required|string',
                'file_storage_directory_destination' => 'required|string',
            ]);

            $this->file_storage_directory_source = trim($this->file_storage_directory_source);
            $this->file_storage_directory_source = str($this->file_storage_directory_source)->start('/')->value();
            $this->file_storage_directory_destination = trim($this->file_storage_directory_destination);
            $this->file_storage_directory_destination = str($this->file_storage_directory_destination)->start('/')->value();

            // Validate paths to prevent command injection
            validateShellSafePath($this->file_storage_directory_source, 'storage source path');
            validateShellSafePath($this->file_storage_directory_destination, 'storage destination path');

            LocalFileVolume::create([
                'fs_path' => $this->file_storage_directory_source,
                'mount_path' => $this->file_storage_directory_destination,
                'is_directory' => true,
                'resource_id' => $this->resource->id,
                'resource_type' => get_class($this->resource),
            ]);

            $this->clearForm();
            $this->activeTab = 'directories';
            $this->refreshStorages();
            $this->dispatch('configurationChanged');
            $this->dispatch('success', 'Directory mount added successfully');
            $this->dispatch('closeStorageModal', 'directory');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function clearForm()
    {
        $this->name = '';
        $this->mount_path = '';
        $this->host_path = null;
        $this->file_storage_path = '';
        $this->file_storage_content = null;
        $this->file_storage_directory_destination = '';
        $this->host_file_storage_source = '';
        $this->host_file_storage_destination = '';

        if (str($this->resource->getMorphClass())->contains('Standalone')) {
            $this->file_storage_directory_source = database_configuration_dir()."/{$this->resource->uuid}";
        } else {
            $this->file_storage_directory_source = application_configuration_dir()."/{$this->resource->uuid}";
        }
    }

    public function fileStorageHostPath(): string
    {
        if (method_exists($this->resource, 'workdir')) {
            return $this->resource->workdir();
        }

        if ($this->resource->getMorphClass() === Application::class) {
            return application_configuration_dir().'/'.$this->resource->uuid;
        }

        if (str($this->resource->getMorphClass())->contains('Standalone')) {
            return database_configuration_dir().'/'.$this->resource->uuid;
        }

        throw new \Exception('No valid resource type for file mount storage type!');
    }

    public function fileStoragePreviewPath(): string
    {
        $path = str($this->file_storage_path)->trim();

        if ($path->isEmpty()) {
            return $this->fileStorageHostPath().'/';
        }

        return $this->fileStorageHostPath().$path->start('/')->value();
    }

    public function render()
    {
        return view('livewire.project.service.storage');
    }
}
