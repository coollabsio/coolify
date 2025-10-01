<?php

namespace App\Livewire\Project\Service;

use App\Models\LocalPersistentVolume;
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

    public string $file_storage_directory_source = '';

    public string $file_storage_directory_destination = '';

    public function getListeners()
    {
        $teamId = auth()->user()->currentTeam()->id;

        return [
            "echo-private:team.{$teamId},FileStorageChanged" => 'refreshStoragesFromEvent',
            'refreshStorages',
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

        if ($this->resource->getMorphClass() === \App\Models\Application::class) {
            if ($this->resource->destination->server->isSwarm()) {
                $this->isSwarm = true;
            }
        }

        $this->refreshStorages();
    }

    public function refreshStoragesFromEvent()
    {
        $this->refreshStorages();
        $this->dispatch('warning', 'File storage changed. Usually it means that the file / directory is already defined on the server, so Coolify set it up for you properly on the UI.');
    }

    public function refreshStorages()
    {
        $this->fileStorage = $this->resource->fileStorages()->get();
        $this->resource->refresh();
    }

    public function getFilesProperty()
    {
        return $this->fileStorage->where('is_directory', false);
    }

    public function getDirectoriesProperty()
    {
        return $this->fileStorage->where('is_directory', true);
    }

    public function getVolumeCountProperty()
    {
        return $this->resource->persistentStorages()->count();
    }

    public function getFileCountProperty()
    {
        return $this->files->count();
    }

    public function getDirectoryCountProperty()
    {
        return $this->directories->count();
    }

    public function submitPersistentVolume()
    {
        try {
            $this->authorize('update', $this->resource);

            $this->validate([
                'name' => 'required|string',
                'mount_path' => 'required|string',
                'host_path' => $this->isSwarm ? 'required|string' : 'string|nullable',
            ]);

            $name = $this->resource->uuid.'-'.$this->name;

            LocalPersistentVolume::create([
                'name' => $name,
                'mount_path' => $this->mount_path,
                'host_path' => $this->host_path,
                'resource_id' => $this->resource->id,
                'resource_type' => $this->resource->getMorphClass(),
            ]);
            $this->resource->refresh();
            $this->dispatch('success', 'Volume added successfully');
            $this->dispatch('closeStorageModal', 'volume');
            $this->clearForm();
            $this->refreshStorages();
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

            $this->file_storage_path = trim($this->file_storage_path);
            $this->file_storage_path = str($this->file_storage_path)->start('/')->value();

            if ($this->resource->getMorphClass() === \App\Models\Application::class) {
                $fs_path = application_configuration_dir().'/'.$this->resource->uuid.$this->file_storage_path;
            } elseif (str($this->resource->getMorphClass())->contains('Standalone')) {
                $fs_path = database_configuration_dir().'/'.$this->resource->uuid.$this->file_storage_path;
            } else {
                throw new \Exception('No valid resource type for file mount storage type!');
            }

            \App\Models\LocalFileVolume::create([
                'fs_path' => $fs_path,
                'mount_path' => $this->file_storage_path,
                'content' => $this->file_storage_content,
                'is_directory' => false,
                'resource_id' => $this->resource->id,
                'resource_type' => get_class($this->resource),
            ]);

            $this->dispatch('success', 'File mount added successfully');
            $this->dispatch('closeStorageModal', 'file');
            $this->clearForm();
            $this->refreshStorages();
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

            \App\Models\LocalFileVolume::create([
                'fs_path' => $this->file_storage_directory_source,
                'mount_path' => $this->file_storage_directory_destination,
                'is_directory' => true,
                'resource_id' => $this->resource->id,
                'resource_type' => get_class($this->resource),
            ]);

            $this->dispatch('success', 'Directory mount added successfully');
            $this->dispatch('closeStorageModal', 'directory');
            $this->clearForm();
            $this->refreshStorages();
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

        if (str($this->resource->getMorphClass())->contains('Standalone')) {
            $this->file_storage_directory_source = database_configuration_dir()."/{$this->resource->uuid}";
        } else {
            $this->file_storage_directory_source = application_configuration_dir()."/{$this->resource->uuid}";
        }
    }

    public function render()
    {
        return view('livewire.project.service.storage');
    }
}
