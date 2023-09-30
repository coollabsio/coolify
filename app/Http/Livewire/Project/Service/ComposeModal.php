<?php

namespace App\Http\Livewire\Project\Service;

use Livewire\Component;

class ComposeModal extends Component
{
    public string $raw;
    public string $actual;
    public function render()
    {
        return view('livewire.project.service.compose-modal');
    }
    public function submit() {
        $this->emit('warning', "Saving new docker compose...");
        $this->emit('saveCompose', $this->raw);
    }
}
