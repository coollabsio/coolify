<?php

namespace App\Http\Livewire\Project\Service;

use Livewire\Component;

class Modal extends Component
{
    public function serviceStatusUpdated() {
        $this->emit('serviceStatusUpdated');
    }
    public function render()
    {
        return view('livewire.project.service.modal');
    }
}
