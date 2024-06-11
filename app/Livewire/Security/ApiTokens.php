<?php

namespace App\Livewire\Security;

use Livewire\Component;

class ApiTokens extends Component
{
    public ?string $description = null;

    public $tokens = [];

    public function render()
    {
        return view('livewire.security.api-tokens');
    }

    public function mount()
    {
        $this->tokens = auth()->user()->tokens;
    }

    public function addNewToken()
    {
        try {
            $this->validate([
                'description' => 'required|min:3|max:255',
            ]);
            $token = auth()->user()->createToken($this->description);
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
