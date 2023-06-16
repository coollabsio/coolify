<?php

namespace App\Http\Livewire\Project\Application\Storages;

use Livewire\Component;

class Show extends Component
{
    public $storage;
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
    }
    public function delete()
    {
        $this->storage->delete();
        $this->emit('refreshStorages');
    }
}
