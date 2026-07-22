<?php

namespace App\Livewire\Security\CloudProviderToken;

use App\Models\CloudProviderToken;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Http;
use Livewire\Component;

class Show extends Component
{
    use AuthorizesRequests;

    public CloudProviderToken $cloudProviderToken;

    public string $name = '';

    public ?string $description = null;

    protected function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
        ];
    }

    protected function messages(): array
    {
        return [
            'name.required' => 'Token name is required.',
        ];
    }

    public function mount(string $cloud_token_uuid): void
    {
        try {
            $this->cloudProviderToken = CloudProviderToken::ownedByCurrentTeam()
                ->whereUuid($cloud_token_uuid)
                ->firstOrFail();

            $this->authorize('view', $this->cloudProviderToken);

            $this->name = $this->cloudProviderToken->name;
            $this->description = $this->cloudProviderToken->description;
        } catch (AuthorizationException) {
            abort(403, 'You do not have permission to view this cloud token.');
        } catch (\Throwable) {
            abort(404);
        }
    }

    public function save(): void
    {
        $this->authorize('update', $this->cloudProviderToken);
        $this->validate();

        $description = trim($this->description ?? '');

        $this->cloudProviderToken->update([
            'name' => $this->name,
            'description' => $description === '' ? null : $description,
        ]);

        auditLog('ui.cloud_token.updated', [
            'team_id' => currentTeam()->id,
            'cloud_token_uuid' => $this->cloudProviderToken->uuid,
            'cloud_token_name' => $this->cloudProviderToken->name,
            'provider' => $this->cloudProviderToken->provider,
        ]);

        $this->dispatch('success', 'Cloud provider token updated.');
    }

    public function validateToken(): void
    {
        $this->authorize('view', $this->cloudProviderToken);

        $isValid = match ($this->cloudProviderToken->provider) {
            'hetzner' => $this->validateHetznerToken($this->cloudProviderToken->token),
            'digitalocean' => $this->validateDigitalOceanToken($this->cloudProviderToken->token),
            'vultr' => $this->validateVultrToken($this->cloudProviderToken->token),
            default => false,
        };

        $providerName = $this->providerName();

        $this->dispatch(
            $isValid ? 'success' : 'error',
            $isValid
                ? "{$providerName} token is valid."
                : "{$providerName} token validation failed. Please check the token."
        );

        auditLog('ui.cloud_token.validated', [
            'team_id' => currentTeam()->id,
            'cloud_token_uuid' => $this->cloudProviderToken->uuid,
            'cloud_token_name' => $this->cloudProviderToken->name,
            'provider' => $this->cloudProviderToken->provider,
            'valid' => $isValid,
        ]);
    }

    public function delete(): mixed
    {
        $this->authorize('delete', $this->cloudProviderToken);

        if ($this->cloudProviderToken->hasServers()) {
            $serverCount = $this->cloudProviderToken->servers()->count();
            $this->dispatch('error', "Cannot delete this token. It is currently used by {$serverCount} server(s). Please reassign those servers to a different token first.");

            return null;
        }

        auditLog('ui.cloud_token.deleted', [
            'team_id' => currentTeam()->id,
            'cloud_token_uuid' => $this->cloudProviderToken->uuid,
            'cloud_token_name' => $this->cloudProviderToken->name,
            'provider' => $this->cloudProviderToken->provider,
        ]);

        $this->cloudProviderToken->delete();

        return redirectRoute($this, 'security.cloud-tokens');
    }

    public function providerName(): string
    {
        return match ($this->cloudProviderToken->provider) {
            'digitalocean' => 'DigitalOcean',
            'vultr' => 'Vultr',
            default => 'Hetzner',
        };
    }

    private function validateHetznerToken(string $token): bool
    {
        try {
            return Http::withToken($token)
                ->timeout(10)
                ->get('https://api.hetzner.cloud/v1/servers?per_page=1')
                ->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    private function validateDigitalOceanToken(string $token): bool
    {
        try {
            return Http::withToken($token)
                ->timeout(10)
                ->get('https://api.digitalocean.com/v2/account')
                ->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    private function validateVultrToken(string $token): bool
    {
        try {
            return Http::withToken($token)
                ->timeout(10)
                ->get('https://api.vultr.com/v2/account')
                ->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function render()
    {
        return view('livewire.security.cloud-provider-token.show');
    }
}
