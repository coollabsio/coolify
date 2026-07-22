<?php

namespace App\Livewire\Security\DockerRegistry;

use App\Models\DockerRegistry;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Create extends Component
{
    use AuthorizesRequests;

    public string $name = '';

    public ?string $description = null;

    public ?string $registryUrl = null;

    public ?string $username = null;

    public ?string $password = null;

    protected function rules(): array
    {
        return [
            'name' => ValidationPatterns::nameRules(),
            'description' => ValidationPatterns::descriptionRules(),
            'registryUrl' => 'nullable|string',
            'username' => 'nullable|string',
            'password' => 'nullable|string',
        ];
    }

    public function createRegistry()
    {
        $this->validate();

        $username = $this->username !== null ? trim($this->username) : null;
        $password = $this->password !== null ? trim($this->password) : null;
        $registryUrl = $this->registryUrl !== null ? trim($this->registryUrl) : null;

        if ($username === '') {
            $username = null;
        }

        if ($password === '') {
            $password = null;
        }

        if ($registryUrl === '') {
            $registryUrl = null;
        }

        // Validate that both username and password are provided together
        if (($username !== null && $password === null) || ($password !== null && $username === null)) {
            $this->addError('username', 'Please provide both username and password for the registry.');
            $this->addError('password', 'Please provide both username and password for the registry.');

            return;
        }

        try {
            $this->authorize('create', DockerRegistry::class);

            DockerRegistry::create([
                'name' => $this->name,
                'description' => $this->description,
                'registry_url' => $registryUrl,
                'username' => $username,
                'password' => $password,
                'team_id' => currentTeam()->id,
            ]);

            return redirectRoute($this, 'security.docker-registry.index');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.security.docker-registry.create');
    }
}
