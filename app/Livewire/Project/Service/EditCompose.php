<?php

namespace App\Livewire\Project\Service;

use App\Models\Service;
use Livewire\Component;

class EditCompose extends Component
{
    public Service $service;

    public $serviceId;

    protected $listeners = [
        'refreshEnvs',
        'envsUpdated',
        'refresh' => 'envsUpdated',
    ];

    protected $rules = [
        'service.docker_compose_raw' => 'required',
        'service.docker_compose' => 'required',
        'service.is_container_label_escape_enabled' => 'required',
    ];

    public function envsUpdated()
    {
        $this->dispatch('saveCompose', $this->service->docker_compose_raw);
        $this->refreshEnvs();
    }

    public function refreshEnvs()
    {
        $this->service = Service::find($this->serviceId);
    }

    public function mount()
    {
        $this->service = Service::find($this->serviceId);
    }

    public function saveEditedCompose()
    {
        $this->dispatch('info', 'Saving new docker compose...');
        $this->dispatch('saveCompose', $this->service->docker_compose_raw);
        $this->dispatch('refreshStorages');
    }

    public function instantSave()
    {
        $this->validate([
            'service.is_container_label_escape_enabled' => 'required',
        ]);
        $this->service->save(['is_container_label_escape_enabled' => $this->service->is_container_label_escape_enabled]);
        $this->dispatch('success', 'Service updated successfully');
    }

    public function render()
    {
        return view('livewire.project.service.edit-compose');
    }
}
