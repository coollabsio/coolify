<?php

namespace App\Livewire\Project\Service;

use App\Models\Service;
use Livewire\Component;

class EditCompose extends Component
{
    public Service $service;
    public $serviceId;
    protected $rules = [
        'service.docker_compose_raw' => 'required',
        'service.docker_compose' => 'required',
    ];
    public function mount() {
        $this->service = Service::find($this->serviceId);
    }

    public function saveEditedCompose() {
        $this->dispatch('warning', "Saving new docker compose...");
        $this->dispatch('saveCompose', $this->service->docker_compose_raw);
    }
    public function render()
    {
        return view('livewire.project.service.edit-compose');
    }
}
