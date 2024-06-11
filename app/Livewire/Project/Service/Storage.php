<?php

namespace App\Livewire\Project\Service;

use App\Models\LocalPersistentVolume;
use Livewire\Component;

class Storage extends Component
{
    public $resource;

    public function getListeners()
    {
        return [
            'addNewVolume',
            'refresh_storages' => '$refresh',
        ];
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
            $this->dispatch('refresh_storages');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.project.service.storage');
    }
}
