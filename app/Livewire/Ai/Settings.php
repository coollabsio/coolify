<?php

namespace App\Livewire\Ai;

use App\Ai\TestConnection;
use App\Enums\AiProvider;
use App\Models\AiProviderCredential;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Settings extends Component
{
    use AuthorizesRequests;

    public bool $isAiEnabled = true;

    public string $newProvider = 'openai';

    public string $newModel = '';

    public string $newApiKey = '';

    public ?string $newBaseUrl = null;

    public function mount(): void
    {
        $this->isAiEnabled = currentTeam()->is_ai_assistant_enabled;
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
                'newBaseUrl' => 'nullable|url|max:2000',
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
        return view('livewire.ai.settings', [
            'credentials' => AiProviderCredential::ownedByCurrentTeam()->get(),
            'providers' => AiProvider::cases(),
        ]);
    }
}
