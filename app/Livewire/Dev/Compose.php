<?php

namespace App\Livewire\Dev;

use Livewire\Component;

class Compose extends Component
{
    public string $compose = '';

    public string $base64 = '';

    public $services;

    public function mount()
    {
        $this->services = get_service_templates();
    }

    public function setService(string $selected)
    {
        $this->base64 = data_get($this->services, $selected.'.compose');
        if ($this->base64) {
            $this->compose = base64_decode($this->base64);
        }
    }

    public function updatedCompose($value)
    {
        $this->base64 = base64_encode($value);
    }

    public function render()
    {
        return view('livewire.dev.compose');
    }
}
