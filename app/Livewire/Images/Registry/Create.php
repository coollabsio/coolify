<?php

namespace App\Livewire\Images\Registry;

use App\Models\DockerRegistry;
use Livewire\Component;

class Create extends Component
{
    public string $name = '';
    public string $type = 'docker_hub';
    public ?string $url = null;
    public ?string $username = null;
    public ?string $token = null;

    protected $rules = [
        'name' => 'required|string|max:255',
        'type' => 'required|string',
        'url' => 'nullable|string|max:255',
        'username' => 'nullable|string|max:255',
        'token' => 'nullable|string',
    ];

    public function getRegistryTypesProperty()
    {
        return DockerRegistry::getTypes();
    }

    public function submit()
    {
        $this->validate();

        DockerRegistry::create([
            'name' => $this->name,
            'type' => $this->type,
            'url' => $this->type === 'custom' ? $this->url : 'docker.io',
            'username' => $this->username,
            'token' => $this->token,
        ]);

        $this->dispatch('registry-added');
        $this->dispatch('success', 'Registry added successfully.');
        $this->dispatch('close-modal');
    }

    public function render()
    {
        return view('livewire.images.registry.create');
    }
}
