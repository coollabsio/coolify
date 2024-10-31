<?php

namespace App\Livewire\Security;

use App\Models\InstanceSettings;
use Livewire\Component;

class ApiTokens extends Component
{
    public ?string $description = null;

    public $tokens = [];

    public bool $viewSensitiveData = false;

    public bool $readOnly = false; // Set default to false
    public bool $rootAccess = true; // Set default to true

    public array $permissions = ['*']; // Default to root access

    public $isApiEnabled;

    public function render()
    {
        return view('livewire.security.api-tokens');
    }

    public function mount()
    {
        $this->isApiEnabled = InstanceSettings::get()->is_api_enabled;
        $this->tokens = auth()->user()->tokens->sortByDesc('created_at');
    }

    public function updatedViewSensitiveData()
    {
        if ($this->viewSensitiveData) {
            $this->permissions[] = 'view:sensitive';
            $this->permissions = array_diff($this->permissions, ['*']);
            $this->rootAccess = false;
        } else {
            $this->permissions = array_diff($this->permissions, ['view:sensitive']);
        }
        $this->makeSureOneIsSelected();
    }

    public function updatedReadOnly()
    {
        if ($this->readOnly) {
            $this->permissions[] = 'read-only';
            $this->permissions = array_diff($this->permissions, ['*']);
            $this->rootAccess = false;
        } else {
            $this->permissions = array_diff($this->permissions, ['read-only']);
        }
        $this->makeSureOneIsSelected();
    }

    public function updatedRootAccess()
    {
        if ($this->rootAccess) {
            $this->permissions = ['*'];
            $this->readOnly = false;
            $this->viewSensitiveData = false;
        } else {
            $this->readOnly = false; // Uncheck readOnly by default
            $this->permissions = [];
        }
        $this->makeSureOneIsSelected();
    }

    public function makeSureOneIsSelected()
    {
        if (count($this->permissions) == 0) {
            $this->permissions = ['*'];
            $this->rootAccess = true; // Set root access by default
            $this->readOnly = false;
        }
    }

    public function addNewToken()
    {
        try {
            $this->validate([
                'description' => 'required|min:3|max:255',
            ]);
            $token = auth()->user()->createToken($this->description, $this->permissions);
            $this->tokens = auth()->user()->tokens;
            session()->flash('token', $token->plainTextToken);
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    public function revoke(int $id)
    {
        $token = auth()->user()->tokens()->where('id', $id)->first();
        $token->delete();
        $this->tokens = auth()->user()->tokens;
    }
}
