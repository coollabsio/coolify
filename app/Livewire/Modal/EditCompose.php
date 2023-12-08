<?php

namespace App\Livewire\Modal;

use App\Models\Service;
use LivewireUI\Modal\ModalComponent;

class EditCompose extends ModalComponent
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
        return view('livewire.modal.edit-compose');
    }
    public function submit() {
        $this->dispatch('warning', "Saving new docker compose...");
        $this->dispatch('saveCompose', $this->service->docker_compose_raw);
        $this->closeModal();
    }
}
