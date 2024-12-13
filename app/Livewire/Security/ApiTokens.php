<?php

namespace App\Livewire\Security;

use App\Models\InstanceSettings;
use Livewire\Component;

class ApiTokens extends Component
{
    public ?string $description = null;

    public $tokens = [];

    public array $permissions = ['read'];

    public $isApiEnabled;

    public function render()
    {
        return view('livewire.security.api-tokens');
    }

    public function mount()
    {
        $this->isApiEnabled = InstanceSettings::get()->is_api_enabled;
        $this->getTokens();
    }

    private function getTokens()
    {
        $this->tokens = auth()->user()->tokens->sortByDesc('created_at');
    }

    public function updatedPermissions($permissionToUpdate)
    {
        if ($permissionToUpdate == 'root') {
            $this->permissions = ['root'];
        } elseif ($permissionToUpdate == 'read:sensitive' && ! in_array('read', $this->permissions)) {
            $this->permissions[] = 'read';
        } elseif ($permissionToUpdate == 'deploy') {
            $this->permissions = ['deploy'];
        } else {
            if (count($this->permissions) == 0) {
                $this->permissions = ['read'];
            }
        }
        sort($this->permissions);
    }

    public function addNewToken()
    {
        try {
            $this->validate([
                'description' => 'required|min:3|max:255',
            ]);
            $token = auth()->user()->createToken($this->description, array_values($this->permissions));
            $this->getTokens();
            session()->flash('token', $token->plainTextToken);
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    public function revoke(int $id)
    {
        try {
            $token = auth()->user()->tokens()->where('id', $id)->firstOrFail();
            $token->delete();
            $this->getTokens();
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }
}
