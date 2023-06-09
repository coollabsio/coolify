<?php

namespace App\Http\Livewire\Project\Application\Storages;

use App\Models\Application;
use App\Models\LocalPersistentVolume;
use Livewire\Component;

class All extends Component
{
    public Application $application;
    protected $listeners = ['refreshStorages', 'submit'];
    public function refreshStorages()
    {
        $this->application->refresh();
    }
    public function submit($data)
    {
        try {
            LocalPersistentVolume::create([
                'name' => $data['name'],
                'mount_path' => $data['mount_path'],
                'host_path' => $data['host_path'],
                'resource_id' => $this->application->id,
                'resource_type' => Application::class,
            ]);
            $this->application->refresh();
            $this->emit('clearAddStorage');
        } catch (\Exception $e) {
            return general_error_handler(err: $e, that: $this);
        }
    }
}
