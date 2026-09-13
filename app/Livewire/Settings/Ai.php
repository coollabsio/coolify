<?php

namespace App\Livewire\Settings;

use App\Ai\TestConnection;
use App\Enums\AiProvider;
use App\Models\AiProviderCredential;
use App\Models\InstanceSettings;
use App\Rules\SafeExternalUrl;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Ai extends Component
{
    use AuthorizesRequests;

    public InstanceSettings $settings;

    public bool $isInstanceAdmin = false;

    #[Validate('boolean')]
    public bool $is_ai_assistant_enabled = false;

    #[Validate('boolean')]
    public bool $is_mcp_server_enabled = false;

    public bool $isAiEnabled = true;

    public string $newProvider = 'openai';

    public string $newModel = '';

    public string $newApiKey = '';

    public ?string $newBaseUrl = null;

    public function mount()
    {
        if (! auth()->user()->isAdmin()) {
            return redirect()->route('dashboard');
        }
        $this->settings = instanceSettings();
        $this->isInstanceAdmin = isInstanceAdmin();
        $this->is_ai_assistant_enabled = $this->settings->is_ai_assistant_enabled ?? false;
        $this->is_mcp_server_enabled = $this->settings->is_mcp_server_enabled ?? false;
        $this->isAiEnabled = currentTeam()->is_ai_assistant_enabled;
    }

    public function instantSave()
    {
        try {
            $this->authorize('update', $this->settings);
            $this->validate([
                'is_ai_assistant_enabled' => 'boolean',
                'is_mcp_server_enabled' => 'boolean',
            ]);
            $this->settings->is_ai_assistant_enabled = $this->is_ai_assistant_enabled;
            $this->settings->is_mcp_server_enabled = $this->is_mcp_server_enabled;
            $this->settings->save();
            $this->dispatch('success', 'Settings updated!');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function saveTeamToggle()
    {
        try {
            $this->authorize('update', currentTeam());
            $team = currentTeam();
            $team->is_ai_assistant_enabled = $this->isAiEnabled;
            $team->save();
            $this->dispatch('success', 'AI assistant setting saved.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function addCredential()
    {
        try {
            $this->authorize('create', AiProviderCredential::class);
            $this->validate([
                'newProvider' => 'required|string|in:'.implode(',', array_column(AiProvider::cases(), 'value')),
                'newModel' => 'required|string|max:255',
                'newApiKey' => 'required|string|max:1000',
                // Block SSRF to internal services (TestConnection echoes the response
                // body back to the admin); reuse the shared internal-host allowlist.
                'newBaseUrl' => ['nullable', 'url', 'max:2000', new SafeExternalUrl],
            ]);

            $isFirst = AiProviderCredential::where('team_id', currentTeam()->id)->count() === 0;

            $cred = AiProviderCredential::create([
                'team_id' => currentTeam()->id,
                'provider' => AiProvider::from($this->newProvider),
                'model' => $this->newModel,
                'api_key' => $this->newApiKey,
                'base_url' => $this->newBaseUrl ?: null,
                'is_default' => $isFirst,
                'enabled' => true,
            ]);

            auditLog('ui.ai.credential.created', ['team_id' => currentTeam()->id, 'credential_id' => $cred->id, 'provider' => $cred->provider->value]);
            $this->reset('newModel', 'newApiKey', 'newBaseUrl');
            $this->dispatch('success', 'Credential added.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function setDefault(int $id)
    {
        try {
            $cred = AiProviderCredential::ownedByCurrentTeam()->find($id);
            if (! $cred) {
                return $this->dispatch('error', 'Credential not found.');
            }
            $this->authorize('update', $cred);
            $cred->makeDefault();
            $this->dispatch('success', 'Default provider updated.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function testCredential(int $id)
    {
        try {
            $cred = AiProviderCredential::ownedByCurrentTeam()->find($id);
            if (! $cred) {
                return $this->dispatch('error', 'Credential not found.');
            }
            $this->authorize('view', $cred);
            $result = app(TestConnection::class)->handle($cred);
            $result['ok']
                ? $this->dispatch('success', $result['message'])
                : $this->dispatch('error', 'Connection failed: '.$result['message']);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function deleteCredential(int $id)
    {
        try {
            $cred = AiProviderCredential::ownedByCurrentTeam()->find($id);
            if (! $cred) {
                return $this->dispatch('error', 'Credential not found.');
            }
            $this->authorize('delete', $cred);
            $cred->delete();
            auditLog('ui.ai.credential.deleted', ['team_id' => currentTeam()->id, 'credential_id' => $id]);
            $this->dispatch('success', 'Credential deleted.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.settings.ai', [
            'credentials' => AiProviderCredential::ownedByCurrentTeam()->get(),
            'providers' => AiProvider::cases(),
        ]);
    }
}
