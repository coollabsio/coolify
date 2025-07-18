<?php

namespace App\Livewire\Project\Shared\Storages;

use App\Models\Application;
use App\Models\LocalFileVolume;
use Livewire\Component;

class Add extends Component
{
    public $resource;

    public $uuid;

    public $parameters;

    public $isSwarm = false;

    public string $name;

    public string $mount_path;

    public ?string $host_path = null;

    public string $file_storage_path;

    public ?string $file_storage_content = null;

    public string $file_storage_directory_source;

    public string $file_storage_directory_destination;

    public $rules = [
        'name' => 'required|string',
        'mount_path' => 'required|string',
        'host_path' => 'string|nullable',
        'file_storage_path' => 'string',
        'file_storage_content' => 'nullable|string',
        'file_storage_directory_source' => 'string',
        'file_storage_directory_destination' => 'string',
    ];

    protected $listeners = ['clearAddStorage' => 'clear'];

    protected $validationAttributes = [
        'name' => 'name',
        'mount_path' => 'mount',
        'host_path' => 'host',
        'file_storage_path' => 'file storage path',
        'file_storage_content' => 'file storage content',
        'file_storage_directory_source' => 'file storage directory source',
        'file_storage_directory_destination' => 'file storage directory destination',
    ];

    public function mount()
    {
        if (str($this->resource->getMorphClass())->contains('Standalone')) {
            $this->file_storage_directory_source = database_configuration_dir()."/{$this->resource->uuid}";
        } else {
            $this->file_storage_directory_source = application_configuration_dir()."/{$this->resource->uuid}";
        }
        $this->uuid = $this->resource->uuid;
        $this->parameters = get_route_parameters();
        if (data_get($this->parameters, 'application_uuid')) {
            $applicationUuid = $this->parameters['application_uuid'];
            $application = Application::where('uuid', $applicationUuid)->first();
            if (! $application) {
                abort(404);
            }
            if ($application->destination->server->isSwarm()) {
                $this->isSwarm = true;
                $this->rules['host_path'] = 'required|string';
            }
        }
    }

    public function submitFileStorage()
    {
        try {
            $this->validate([
                'file_storage_path' => 'string',
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

            LocalFileVolume::create(
                [
                    'fs_path' => $fs_path,
                    'mount_path' => $this->file_storage_path,
                    'content' => $this->file_storage_content,
                    'is_directory' => false,
                    'resource_id' => $this->resource->id,
                    'resource_type' => get_class($this->resource),
                ],
            );
            $this->dispatch('refreshStorages');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function submitFileStorageDirectory()
    {
        try {
            $this->validate([
                'file_storage_directory_source' => 'string',
                'file_storage_directory_destination' => 'string',
            ]);

            $this->file_storage_directory_source = trim($this->file_storage_directory_source);
            $this->file_storage_directory_source = str($this->file_storage_directory_source)->start('/')->value();
            $this->file_storage_directory_destination = trim($this->file_storage_directory_destination);
            $this->file_storage_directory_destination = str($this->file_storage_directory_destination)->start('/')->value();

            LocalFileVolume::create(
                [
                    'fs_path' => $this->file_storage_directory_source,
                    'mount_path' => $this->file_storage_directory_destination,
                    'is_directory' => true,
                    'resource_id' => $this->resource->id,
                    'resource_type' => get_class($this->resource),
                ],
            );
            $this->dispatch('refreshStorages');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function submitPersistentVolume()
    {
        try {
            $this->validate([
                'name' => 'required|string',
                'mount_path' => 'required|string',
                'host_path' => 'string|nullable',
            ]);
            $name = $this->uuid.'-'.$this->name;
            $this->dispatch('addNewVolume', [
                'name' => $name,
                'mount_path' => $this->mount_path,
                'host_path' => $this->host_path,
            ]);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function clear()
    {
        $this->name = '';
        $this->mount_path = '';
        $this->host_path = null;
    }
}
