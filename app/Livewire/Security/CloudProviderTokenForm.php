<?php

namespace App\Livewire\Security;

use App\Models\CloudProviderToken;
use App\Services\OpenStackService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Http;
use Livewire\Component;

class CloudProviderTokenForm extends Component
{
    use AuthorizesRequests;

    public bool $modal_mode = false;

    public string $provider = 'hetzner';

    public string $token = '';

    public string $name = '';

    // OpenStack credentials (application credential based Keystone v3 auth).
    public string $os_auth_url = '';

    public string $os_application_credential_id = '';

    public string $os_application_credential_secret = '';

    public string $os_region = '';

    public function mount()
    {
        $this->authorize('create', CloudProviderToken::class);
    }

    protected function rules(): array
    {
        $rules = [
            'provider' => 'required|string|in:hetzner,digitalocean,openstack',
            'name' => 'required|string|max:255',
        ];

        if ($this->provider === 'openstack') {
            $rules = array_merge($rules, [
                'os_auth_url' => 'required|url',
                'os_application_credential_id' => 'required|string',
                'os_application_credential_secret' => 'required|string',
                'os_region' => 'nullable|string',
            ]);
        } else {
            $rules['token'] = 'required|string';
        }

        return $rules;
    }

    protected function messages(): array
    {
        return [
            'provider.required' => 'Please select a cloud provider.',
            'provider.in' => 'Invalid cloud provider selected.',
            'token.required' => 'API token is required.',
            'name.required' => 'Token name is required.',
            'os_auth_url.required' => 'The Keystone auth URL is required.',
            'os_auth_url.url' => 'The Keystone auth URL must be a valid URL.',
            'os_application_credential_id.required' => 'The application credential ID is required.',
            'os_application_credential_secret.required' => 'The application credential secret is required.',
        ];
    }

    private function validateToken(string $provider, string $token): bool
    {
        try {
            if ($provider === 'hetzner') {
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer '.$token,
                ])->timeout(10)->get('https://api.hetzner.cloud/v1/servers');

                return $response->successful();
            }

            return false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @return array{auth_url: string, application_credential_id: string, application_credential_secret: string, region: ?string}
     */
    private function openstackCredentials(): array
    {
        return [
            'auth_url' => rtrim(trim($this->os_auth_url), '/'),
            'application_credential_id' => trim($this->os_application_credential_id),
            'application_credential_secret' => trim($this->os_application_credential_secret),
            'region' => $this->os_region !== '' ? trim($this->os_region) : null,
        ];
    }

    private function validateOpenstackCredentials(array $credentials): bool
    {
        try {
            (new OpenStackService($credentials))->authenticate();

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function addToken()
    {
        $this->validate();

        try {
            if ($this->provider === 'openstack') {
                $credentials = $this->openstackCredentials();

                if (! $this->validateOpenstackCredentials($credentials)) {
                    return $this->dispatch('error', 'Could not authenticate against OpenStack. Please check your credentials.');
                }

                $tokenValue = json_encode($credentials);
            } else {
                if (! $this->validateToken($this->provider, $this->token)) {
                    return $this->dispatch('error', 'Invalid API token. Please check your token and try again.');
                }

                $tokenValue = $this->token;
            }

            $savedToken = CloudProviderToken::create([
                'team_id' => currentTeam()->id,
                'provider' => $this->provider,
                'token' => $tokenValue,
                'name' => $this->name,
            ]);

            $this->reset([
                'token', 'name',
                'os_auth_url', 'os_application_credential_id', 'os_application_credential_secret', 'os_region',
            ]);

            // Dispatch event with token ID so parent components can react
            $this->dispatch('tokenAdded', tokenId: $savedToken->id);

            $this->dispatch('success', 'Cloud provider token added successfully.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.security.cloud-provider-token-form');
    }
}
