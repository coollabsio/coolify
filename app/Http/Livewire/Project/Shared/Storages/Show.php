<?php

namespace App\Http\Livewire\Project\Shared\Storages;

use Livewire\Component;
use Visus\Cuid2\Cuid2;

class Show extends Component
{
    public $storage;
    public string|null $modalId = null;
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
        $this->emit('success', 'Storage updated successfully');
    }

    public function delete()
    {
        $this->storage->delete();
        $this->emit('refreshStorages');
    }
}
