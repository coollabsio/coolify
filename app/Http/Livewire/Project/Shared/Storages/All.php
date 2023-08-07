<?php

namespace App\Http\Livewire\Project\Shared\Storages;

use App\Models\LocalPersistentVolume;
use Livewire\Component;

class All extends Component
{
    public $resource;
    protected $listeners = ['refreshStorages', 'submit'];
    public function refreshStorages()
    {
        $this->resource->refresh();
    }
    public function submit($data)
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
        } catch (\Exception $e) {
            return general_error_handler(err: $e, that: $this);
        }
    }
}
