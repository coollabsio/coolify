<?php

namespace App\Livewire\Security\CloudInitScript;

use App\Models\CloudInitScript;
use App\Rules\ValidCloudInitYaml;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Show extends Component
{
    use AuthorizesRequests;

    public CloudInitScript $cloudInitScript;

    public string $name = '';

    public string $script = '';

    protected function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'script' => ['required', 'string', new ValidCloudInitYaml],
        ];
    }

    protected function messages(): array
    {
        return [
            'name.required' => 'Script name is required.',
            'name.max' => 'Script name cannot exceed 255 characters.',
            'script.required' => 'Cloud-init script content is required.',
        ];
    }

    public function mount(string $cloud_init_script_uuid): void
    {
        try {
            $this->cloudInitScript = CloudInitScript::ownedByCurrentTeam()
                ->whereUuid($cloud_init_script_uuid)
                ->firstOrFail();

            $this->authorize('view', $this->cloudInitScript);

            $this->name = $this->cloudInitScript->name;
            $this->script = $this->cloudInitScript->script;
        } catch (AuthorizationException) {
            abort(403, 'You do not have permission to view this cloud-init script.');
        } catch (\Throwable) {
            abort(404);
        }
    }

    public function save(): void
    {
        $this->authorize('update', $this->cloudInitScript);
        $this->validate();

        $this->cloudInitScript->update([
            'name' => $this->name,
            'script' => $this->script,
        ]);

        auditLog('ui.cloud_init_script.updated', [
            'team_id' => currentTeam()->id,
            'cloud_init_script_id' => $this->cloudInitScript->id,
            'cloud_init_script_name' => $this->cloudInitScript->name,
        ]);

        $this->dispatch('success', 'Cloud-init script updated successfully.');
    }

    public function delete(): mixed
    {
        $this->authorize('delete', $this->cloudInitScript);

        $scriptId = $this->cloudInitScript->id;
        $scriptName = $this->cloudInitScript->name;

        $this->cloudInitScript->delete();

        auditLog('ui.cloud_init_script.deleted', [
            'team_id' => currentTeam()->id,
            'cloud_init_script_id' => $scriptId,
            'cloud_init_script_name' => $scriptName,
        ]);

        return redirectRoute($this, 'security.cloud-init-scripts');
    }

    public function render()
    {
        return view('livewire.security.cloud-init-script.show');
    }
}
