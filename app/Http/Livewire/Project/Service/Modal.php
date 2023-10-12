<?php

namespace App\Http\Livewire\Project\Service;

use Livewire\Component;

class Modal extends Component
{
    public function checkStatus() {
        $this->emit('checkStatus');
    }
    public function render()
    {
        return view('livewire.project.service.modal');
    }
}
