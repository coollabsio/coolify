<?php

namespace App\Http\Livewire\Project\Service;

use App\Models\Service;
use Livewire\Component;

class ComposeModal extends Component
{
    public Service $service;
    protected $rules = [
        'service.docker_compose_raw' => 'required',
        'service.docker_compose' => 'required',
    ];
    public function render()
    {
        return view('livewire.project.service.compose-modal');
    }
    public function submit() {
        $this->emit('warning', "Saving new docker compose...");
        $this->emit('saveCompose', $this->service->docker_compose_raw);
    }
}
