<?php

namespace App\Livewire\Security\DockerRegistry;

use App\Models\DockerRegistry;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Show extends Component
{
    use AuthorizesRequests;

    public DockerRegistry $registry;

    public string $name;

    public ?string $description = null;

    public ?string $registryUrl = null;

    public ?string $username = null;

    public ?string $password = null;

    public bool $hasPassword = false;

    public ?string $originalUsername = null;

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

    public function mount()
    {
        try {
            $this->registry = DockerRegistry::ownedByCurrentTeam()
                ->whereUuid(request()->registry_uuid)
                ->firstOrFail();

            $this->authorize('view', $this->registry);

            $this->name = $this->registry->name;
            $this->description = $this->registry->description;
            $this->registryUrl = $this->registry->registry_url;
            $this->username = $this->registry->username;
            $this->originalUsername = $this->registry->username;
            $this->password = null;
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            abort(403, 'You do not have permission to view this registry.');
        } catch (\Throwable) {
            abort(404);
        }
    }

    public function save()
    {
        try {
            $this->authorize('update', $this->registry);
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

            $changingUsername = $username !== $this->originalUsername;
            $clearingCredentials = $this->originalUsername !== null && $username === null && $password === null;

            // If changing username or setting password (but not clearing both), require both fields
            if (! $clearingCredentials && ($password !== null || $changingUsername)) {
                if ($username === null || $password === null) {
                    $this->addError('username', 'Please provide a username for the registry.');
                    $this->addError('password', 'Please provide a password or token for the registry.');

                    return;
                }
            }

            $this->registry->name = $this->name;
            $this->registry->description = $this->description;
            $this->registry->registry_url = $registryUrl;
            $this->registry->username = $username;

            if ($password !== null) {
                $this->registry->password = $password;
            } elseif ($username === null) {
                $this->registry->password = null;
            }

            $this->registry->save();
            $this->hasPassword = filled($this->registry->password);
            $this->originalUsername = $this->registry->username;
            $this->password = null;

            $this->dispatch('success', 'Docker registry updated.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function delete()
    {
        try {
            $this->authorize('delete', $this->registry);
            $this->registry->safeDelete();

            return redirectRoute($this, 'security.docker-registry.index');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.security.docker-registry.show');
    }
}
