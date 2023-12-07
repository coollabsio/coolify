<?php

namespace App\Livewire\Project\Service;

use Livewire\Component;

class Modal extends Component
{
    public function checkStatus() {
        $this->dispatch('checkStatus');
    }
    public function render()
    {
        return view('livewire.project.service.modal');
    }
}
