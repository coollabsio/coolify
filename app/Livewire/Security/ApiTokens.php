<?php

namespace App\Livewire\Security;

use Livewire\Component;

class ApiTokens extends Component
{
    public ?string $description = null;

    public $tokens = [];

    public bool $viewSensitiveData = false;

    public bool $readOnly = true;

    public array $permissions = ['read-only'];

    public function render()
    {
        return view('livewire.security.api-tokens');
    }

    public function mount()
    {
        $this->tokens = auth()->user()->tokens->sortByDesc('created_at');
    }

    public function updatedViewSensitiveData()
    {
        if ($this->viewSensitiveData) {
            $this->permissions[] = 'view:sensitive';
            $this->permissions = array_diff($this->permissions, ['*']);
        } else {
            $this->permissions = array_diff($this->permissions, ['view:sensitive']);
        }
        if (count($this->permissions) == 0) {
            $this->permissions = ['*'];
        }
    }

    public function updatedReadOnly()
    {
        if ($this->readOnly) {
            $this->permissions[] = 'read-only';
            $this->permissions = array_diff($this->permissions, ['*']);
        } else {
            $this->permissions = array_diff($this->permissions, ['read-only']);
        }
        if (count($this->permissions) == 0) {
            $this->permissions = ['*'];
        }
    }

    public function addNewToken()
    {
        try {
            $this->validate([
                'description' => 'required|min:3|max:255',
            ]);
            // if ($this->viewSensitiveData) {
            //     $this->permissions[] = 'view:sensitive';
            // }
            // if ($this->readOnly) {
            //     $this->permissions[] = 'read-only';
            // }
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
