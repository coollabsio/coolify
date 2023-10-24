<?php

namespace App\Http\Livewire\Project\Service;

use Livewire\Component;

class ComposeModal extends Component
{
    public ?string $raw = null;
    public ?string $actual = null;
    public function render()
    {
        return view('livewire.project.service.compose-modal');
    }
    public function submit() {
        $this->emit('warning', "Saving new docker compose...");
        $this->emit('saveCompose', $this->raw);
    }
}
