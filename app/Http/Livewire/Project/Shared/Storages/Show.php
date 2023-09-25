<?php

namespace App\Http\Livewire\Project\Shared\Storages;

use App\Models\LocalPersistentVolume;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class Show extends Component
{
    public LocalPersistentVolume $storage;
    public bool $isReadOnly = false;
    public ?string $modalId = null;
    public ?string $realName = null;

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
        if ($this->storage->resource_type === 'App\Models\ServiceApplication' || $this->storage->resource_type === 'App\Models\ServiceDatabase') {
            $this->realName = "{$this->storage->service->service->uuid}_{$this->storage->name}";
        } else {
            $this->realName = $this->storage->name;
        }
        $this->modalId = new Cuid2(7);
    }

    public function submit()
    {
        $this->validate();
        $this->storage->save();
        $this->emit('success', 'Storage updated successfully');
    }

    public function delete()
    {
        $this->storage->delete();
        $this->emit('refreshStorages');
    }
}
