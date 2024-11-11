<?php

namespace App\Livewire\Images\Registry;

use App\Models\DockerRegistry;
use Livewire\Component;

class Index extends Component
{
    protected $listeners = ['registry-added' => '$refresh'];

    public function render()
    {
        return view('livewire.images.registry.index', [
            'registries' => DockerRegistry::all()
        ]);
    }
}
