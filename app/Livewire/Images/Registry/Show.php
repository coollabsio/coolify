<?php

namespace App\Livewire\Images\Registry;

use App\Models\DockerRegistry;
use Livewire\Component;

class Show extends Component
{
    public DockerRegistry $registry;
    public string $name = '';
    public string $type = '';
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

    public function mount(DockerRegistry $registry)
    {
        $this->registry = $registry;
        $this->name = $registry->name;
        $this->type = $registry->type;
        $this->url = $registry->url;
        $this->username = $registry->username;
        $this->token = $registry->token;
    }

    public function getRegistryTypesProperty()
    {
        return DockerRegistry::getTypes();
    }

    public function updateRegistry()
    {
        $this->validate();

        $this->registry->update([
            'name' => $this->name,
            'type' => $this->type,
            'url' => $this->type === 'custom' ? $this->url : 'docker.io',
            'username' => $this->username,
            'token' => $this->token,
        ]);

        $this->dispatch('success', 'Registry updated successfully.');
    }

    public function delete()
    {
        // Update all applications using this registry
        $this->registry->applications()
            ->update([
                'docker_registry_id' => null,
                'docker_use_custom_registry' => false
            ]);

        $this->registry->delete();
        $this->dispatch('registry-added');
        $this->dispatch('success', 'Registry deleted successfully.');
    }

    public function render()
    {
        return view('livewire.images.registry.show');
    }

    public function getIsFormDirtyProperty(): bool
    {
        return $this->name !== $this->registry->name
            || $this->type !== $this->registry->type
            || $this->url !== $this->registry->url
            || $this->username !== $this->registry->username
            || $this->token !== $this->registry->token;
    }
}
