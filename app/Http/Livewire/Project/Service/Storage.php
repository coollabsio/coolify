<?php

namespace App\Http\Livewire\Project\Service;

use App\Models\LocalPersistentVolume;
use Livewire\Component;

class Storage extends Component
{
    protected $listeners = ['addNewVolume'];
    public $resource;

    public function render()
    {
        return view('livewire.project.service.storage');
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
            $this->emit('success', 'Storage added successfully');
            $this->emit('clearAddStorage');
            $this->emit('refreshStorages');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
