<?php

namespace App\Http\Livewire\Project\Service;

use App\Models\Service;
use Livewire\Component;
use LivewireUI\Modal\ModalComponent;

class ComposeModal extends ModalComponent
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
    public function render()
    {
        return view('livewire.project.service.compose-modal');
    }
    public function submit() {
        $this->emit('warning', "Saving new docker compose...");
        $this->emit('saveCompose', $this->service->docker_compose_raw);
        $this->closeModal();
    }
}
