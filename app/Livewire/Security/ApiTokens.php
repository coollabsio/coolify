<?php

namespace App\Livewire\Security;

use App\Models\InstanceSettings;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ApiTokens extends Component
{
    use AuthorizesRequests;

    public ?string $description = null;

    public ?int $expiresInDays = 30;

    public $tokens = [];

    public array $permissions = ['read'];

    public array $expirationOptions = [
        7 => '7 days',
        30 => '30 days',
        60 => '60 days',
        90 => '90 days',
        365 => '1 year',
    ];

    public $isApiEnabled;

    #[Locked]
    public bool $canUseRootPermissions = false;

    #[Locked]
    public bool $canUseWritePermissions = false;

    public function render()
    {
        return view('livewire.security.api-tokens');
    }

    public function mount()
    {
        $this->isApiEnabled = InstanceSettings::get()->is_api_enabled;
        $this->canUseRootPermissions = auth()->user()->can('useRootPermissions', PersonalAccessToken::class);
        $this->canUseWritePermissions = auth()->user()->can('useWritePermissions', PersonalAccessToken::class);
        $this->getTokens();
    }

    private function getTokens()
    {
        $this->tokens = auth()->user()->tokens->sortByDesc('created_at');
    }

    public function updatedPermissions($permissionToUpdate)
    {
        // Check if user is trying to use restricted permissions
        if ($permissionToUpdate == 'root' && ! auth()->user()->can('useRootPermissions', PersonalAccessToken::class)) {
            $this->dispatch('error', 'You do not have permission to use root permissions.');
            // Remove root from permissions if it was somehow added
            $this->permissions = array_diff($this->permissions, ['root']);

            return;
        }

        if (in_array($permissionToUpdate, ['write', 'write:sensitive'], true) && ! auth()->user()->can('useWritePermissions', PersonalAccessToken::class)) {
            $this->dispatch('error', 'You do not have permission to use write permissions.');
            // Remove write permissions if they were somehow added
            $this->permissions = array_diff($this->permissions, ['write', 'write:sensitive']);

            return;
        }

        if ($permissionToUpdate == 'root') {
            $this->permissions = ['root'];
        } elseif ($permissionToUpdate == 'read:sensitive' && ! in_array('read', $this->permissions, true)) {
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
            $this->authorize('create', PersonalAccessToken::class);

            // Validate permissions based on user role
            if (in_array('root', $this->permissions, true) && ! auth()->user()->can('useRootPermissions', PersonalAccessToken::class)) {
                throw new \Exception('You do not have permission to create tokens with root permissions.');
            }

            if (array_intersect(['write', 'write:sensitive'], $this->permissions) && ! auth()->user()->can('useWritePermissions', PersonalAccessToken::class)) {
                throw new \Exception('You do not have permission to create tokens with write permissions.');
            }

            $this->validate([
                'description' => 'required|min:3|max:255',
                'expiresInDays' => 'nullable|integer|in:7,30,60,90,365',
            ]);
            $expiresAt = $this->expiresInDays ? now()->addDays($this->expiresInDays) : null;
            $token = auth()->user()->createToken($this->description, array_values($this->permissions), $expiresAt);
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
            $this->authorize('delete', $token);
            $token->delete();
            $this->getTokens();
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }
}
