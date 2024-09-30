<?php

namespace App\Livewire\Project\Service;

use App\Models\LocalPersistentVolume;
use Livewire\Component;

class Storage extends Component
{
    public $resource;

    public $fileStorage;

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
        $this->dispatch('$refresh');
    }

    public function addNewVolume($data)
    {
        try {
            LocalPersistentVolume::create([
                'name' => $data['name'],
                'mount_path' => $data['mount_path'],
                'host_path' => $data['host_path'],
                'resource_id' => $this->resource->id,
                'resource_type' => $this->resource->getMorphClass(),
            ]);
            $this->resource->refresh();
            $this->dispatch('success', 'Storage added successfully');
            $this->dispatch('clearAddStorage');
            $this->dispatch('refreshStorages');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.project.service.storage');
    }
}
