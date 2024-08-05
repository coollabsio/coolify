<?php

namespace App\Livewire\Project\Shared\Storages;

use App\Models\LocalPersistentVolume;
use Livewire\Component;

class Show extends Component
{
    public LocalPersistentVolume $storage;

    public bool $isReadOnly = false;

    public bool $isFirst = true;

    public bool $isService = false;

    public ?string $startedAt = null;

    protected $rules = [
        'storage.name' => 'required|string',
        'storage.mount_path' => 'required|string',
        'storage.host_path' => 'string|nullable',
    ];

    protected $validationAttributes = [
        'name' => 'name',
        'mount_path' => 'mount',
        'host_path' => 'host',
    ];

    public function submit()
    {
        $this->validate();
        $this->storage->save();
        $this->dispatch('success', 'Storage updated successfully');
    }

    public function delete()
    {
        $this->storage->delete();
        $this->dispatch('refreshStorages');
    }
}
