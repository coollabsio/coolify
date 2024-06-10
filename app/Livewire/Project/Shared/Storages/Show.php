<?php

namespace App\Livewire\Project\Shared\Storages;

use App\Models\LocalPersistentVolume;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class Show extends Component
{
    public LocalPersistentVolume $storage;

    public bool $isReadOnly = false;

    public ?string $modalId = null;

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

    public function mount()
    {
        $this->modalId = new Cuid2(7);
    }

    public function submit()
    {
        $this->validate();
        $this->storage->save();
        $this->dispatch('success', 'Storage updated successfully');
    }

    public function delete()
    {
        $this->storage->delete();
        $this->dispatch('refresh_storages');
    }
}
