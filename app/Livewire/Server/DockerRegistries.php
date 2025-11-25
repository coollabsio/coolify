<?php

namespace App\Livewire\Server;

use App\Models\DockerRegistry;
use App\Models\Server;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Component;

class DockerRegistries extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public Server $server;

    public $registries = [];

    public $commonRegistries = [];

    public $showAddModal = false;

    public $editingRegistry = null;

    public $form = [
        'name' => '',
        'registry_url' => '',
        'username' => '',
        'password' => '',
        'is_active' => true,
    ];

    public $validating = false;

    public $importing = false;

    public function mount(string $server_uuid)
    {
        try {
            $this->server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
            $this->authorize('update', $this->server);
            $this->loadRegistries();
            $this->commonRegistries = DockerRegistry::getCommonRegistries();
        } catch (\Throwable $e) {
            return redirect()->route('server.index');
        }
    }

    public function loadRegistries(): void
    {
        $this->registries = $this->server->dockerRegistries()
            ->orderBy('is_active', 'desc')
            ->orderBy('registry_url')
            ->get()
            ->toArray();
    }

    public function openAddModal(?array $preset = null): void
    {
        $this->authorize('update', $this->server);
        $this->resetForm();

        if ($preset) {
            $this->form['name'] = $preset['name'];
            $this->form['registry_url'] = $preset['registry_url'];
        }

        $this->showAddModal = true;
    }

    public function editRegistry(int $registryId): void
    {
        $this->authorize('update', $this->server);
        $registry = DockerRegistry::findOrFail($registryId);

        if ($registry->server_id !== $this->server->id) {
            $this->dispatch('error', 'Registry not found.');

            return;
        }

        $this->editingRegistry = $registryId;
        $this->form = [
            'name' => $registry->name,
            'registry_url' => $registry->registry_url,
            'username' => $registry->username,
            'password' => $registry->password,
            'is_active' => $registry->is_active,
        ];
        $this->showAddModal = true;
    }

    public function saveRegistry()
    {
        try {
            $this->authorize('update', $this->server);

            $this->validate([
                'form.name' => 'required|string|max:255',
                'form.registry_url' => 'required|string|max:500',
                'form.username' => 'required|string|max:255',
                'form.password' => 'required|string',
                'form.is_active' => 'boolean',
            ]);

            if ($this->editingRegistry) {
                $registry = DockerRegistry::findOrFail($this->editingRegistry);
                if ($registry->server_id !== $this->server->id) {
                    throw new \Exception('Registry not found.');
                }
                $registry->update($this->form);
                $message = 'Registry updated successfully.';
            } else {
                DockerRegistry::create([
                    ...$this->form,
                    'server_id' => $this->server->id,
                ]);
                $message = 'Registry added successfully.';
            }

            $this->syncToServer();
            $this->loadRegistries();
            $this->resetForm();
            $this->showAddModal = false;
            $this->dispatch('success', $message);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function deleteRegistry(int $registryId)
    {
        try {
            $this->authorize('update', $this->server);
            $registry = DockerRegistry::findOrFail($registryId);

            if ($registry->server_id !== $this->server->id) {
                throw new \Exception('Registry not found.');
            }

            $registry->delete();
            $this->syncToServer();
            $this->loadRegistries();
            $this->dispatch('success', 'Registry deleted successfully.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function toggleActive(int $registryId)
    {
        try {
            $this->authorize('update', $this->server);
            $registry = DockerRegistry::findOrFail($registryId);

            if ($registry->server_id !== $this->server->id) {
                throw new \Exception('Registry not found.');
            }

            $registry->is_active = ! $registry->is_active;
            $registry->save();

            $this->syncToServer();
            $this->loadRegistries();
            $this->dispatch('success', 'Registry '.($registry->is_active ? 'enabled' : 'disabled').'.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function validateCredentials()
    {
        try {
            $this->authorize('update', $this->server);
            $this->validating = true;

            $this->validate([
                'form.registry_url' => 'required|string',
                'form.username' => 'required|string',
                'form.password' => 'required|string',
            ]);

            if (validateDockerRegistryCredentials(
                $this->server,
                $this->form['registry_url'],
                $this->form['username'],
                $this->form['password']
            )) {
                if ($this->editingRegistry) {
                    $registry = DockerRegistry::find($this->editingRegistry);
                    $registry->last_validated_at = now();
                    $registry->save();
                }
                $this->dispatch('success', 'Credentials are valid!');
            } else {
                $this->dispatch('error', 'Invalid credentials. Please check your username and password.');
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        } finally {
            $this->validating = false;
        }
    }

    public function importFromServer()
    {
        try {
            $this->authorize('update', $this->server);
            $this->importing = true;

            $registries = importDockerConfigFromServer($this->server);

            if (empty($registries)) {
                $this->dispatch('error', 'No registries found in server config or config file does not exist.');

                return;
            }

            $imported = 0;
            foreach ($registries as $registryData) {
                // Check if registry already exists
                $exists = DockerRegistry::where('server_id', $this->server->id)
                    ->where('registry_url', $registryData['registry_url'])
                    ->exists();

                if (! $exists) {
                    DockerRegistry::create([
                        'server_id' => $this->server->id,
                        'name' => $registryData['registry_url'],
                        'registry_url' => $registryData['registry_url'],
                        'username' => $registryData['username'],
                        'password' => $registryData['password'],
                        'is_active' => true,
                    ]);
                    $imported++;
                }
            }

            $this->loadRegistries();
            $this->dispatch('success', "Imported {$imported} registry(ies) from server.");
        } catch (\Throwable $e) {
            return handleError($e, $this);
        } finally {
            $this->importing = false;
        }
    }

    public function syncToServer(): void
    {
        syncDockerRegistriesToServer($this->server);
    }

    private function resetForm(): void
    {
        $this->editingRegistry = null;
        $this->form = [
            'name' => '',
            'registry_url' => '',
            'username' => '',
            'password' => '',
            'is_active' => true,
        ];
    }

    public function render()
    {
        return view('livewire.server.docker-registries');
    }
}
