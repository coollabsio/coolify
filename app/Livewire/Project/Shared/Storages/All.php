<?php

namespace App\Livewire\Project\Shared\Storages;

use Livewire\Component;

class All extends Component
{
    public $resource;

    protected $listeners = ['refreshStorages' => '$refresh'];

    public function getFirstStorageIdProperty()
    {
        if ($this->resource->persistentStorages->isEmpty()) {
            return null;
        }

        // Use the storage with the smallest ID as the "first" one
        // This ensures stability even when storages are deleted
        return $this->resource->persistentStorages->sortBy('id')->first()->id;
    }
}
