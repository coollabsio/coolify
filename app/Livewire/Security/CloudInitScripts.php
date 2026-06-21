<?php

namespace App\Livewire\Security;

use App\Models\CloudInitScript;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class CloudInitScripts extends Component
{
    use AuthorizesRequests;

    public $scripts;

    public function mount()
    {
        $this->authorize('viewAny', CloudInitScript::class);
        $this->loadScripts();
    }

    public function getListeners()
    {
        return [
            'scriptSaved' => 'loadScripts',
        ];
    }

    public function loadScripts()
    {
        $this->scripts = CloudInitScript::ownedByCurrentTeam()->orderBy('created_at', 'desc')->get();
    }

    public function deleteScript(int $scriptId)
    {
        try {
            $script = CloudInitScript::ownedByCurrentTeam()->findOrFail($scriptId);
            $this->authorize('delete', $script);

            $script->delete();
            $this->loadScripts();

            $this->dispatch('success', 'Cloud-init script deleted successfully.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.security.cloud-init-scripts');
    }
}
