<?php

namespace App\Livewire\Images\Registry;

use Livewire\Attributes\Validate;
use App\Models\DockerRegistry;
use Livewire\Component;

class Create extends Component
{
    #[Validate('required|string|max:255|unique:docker_registries,name')]
    public string $name = '';

    #[Validate('required|string')]
    public string $type = 'docker_hub';

    #[Validate('nullable|string|max:255')]
    public ?string $url = null;

    #[Validate('nullable|string|max:255')]
    public ?string $username = null;

    #[Validate('nullable|string')]
    public ?string $token = null;

    public function getRegistryTypesProperty()
    {
        return DockerRegistry::getTypes();
    }

    public function submit()
    {
        // Validation is automatically applied based on attributes
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
};
