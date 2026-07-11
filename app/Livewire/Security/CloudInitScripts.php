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
        CloudInitScript::ownedByCurrentTeam()
            ->whereNull('uuid')
            ->get()
            ->each(function (CloudInitScript $script): void {
                $script->forceFill(['uuid' => new_public_id()])->save();
            });

        $this->scripts = CloudInitScript::ownedByCurrentTeam()->orderBy('created_at', 'desc')->get();
    }

    public function deleteScript(int $scriptId)
    {
        try {
            $script = CloudInitScript::ownedByCurrentTeam()->findOrFail($scriptId);
            $this->authorize('delete', $script);

            $scriptName = $script->name;
            $script->delete();
            $this->loadScripts();

            auditLog('ui.cloud_init_script.deleted', [
                'team_id' => currentTeam()->id,
                'cloud_init_script_id' => $scriptId,
                'cloud_init_script_name' => $scriptName,
            ]);

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
