<?php

namespace App\Livewire\Server\New;

use App\Models\CloudProviderToken;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ByHetzner extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public Collection $available_tokens;

    #[Locked]
    public $private_keys;

    #[Locked]
    public $limit_reached;

    public ?int $selected_token_id = null;

    public string $hetzner_token = '';

    public bool $save_token = false;

    public ?string $token_name = null;

    public function mount()
    {
        $this->authorize('viewAny', CloudProviderToken::class);
        $this->available_tokens = CloudProviderToken::ownedByCurrentTeam()
            ->where('provider', 'hetzner')
            ->get();
    }

    protected function rules(): array
    {
        return [
            'selected_token_id' => 'nullable|integer',
            'hetzner_token' => 'required_without:selected_token_id|string',
            'save_token' => 'boolean',
            'token_name' => 'required_if:save_token,true|nullable|string|max:255',
        ];
    }

    protected function messages(): array
    {
        return [
            'hetzner_token.required_without' => 'Please provide a Hetzner API token or select a saved token.',
            'token_name.required_if' => 'Please provide a name for the token.',
        ];
    }

    public function selectToken(int $tokenId)
    {
        $this->selected_token_id = $tokenId;
    }

    private function validateHetznerToken(string $token): bool
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$token,
            ])->timeout(10)->get('https://api.hetzner.cloud/v1/servers');

            return $response->successful();
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function submit()
    {
        $this->validate();

        try {
            $this->authorize('create', Server::class);

            if (Team::serverLimitReached()) {
                return $this->dispatch('error', 'You have reached the server limit for your subscription.');
            }

            // Determine which token to use
            if ($this->selected_token_id) {
                $token = $this->available_tokens->firstWhere('id', $this->selected_token_id);
                if (! $token) {
                    return $this->dispatch('error', 'Selected token not found.');
                }
                $hetznerToken = $token->token;
            } else {
                $hetznerToken = $this->hetzner_token;

                // Validate the new token before saving
                if (! $this->validateHetznerToken($hetznerToken)) {
                    return $this->dispatch('error', 'Invalid Hetzner API token. Please check your token and try again.');
                }

                // If saving the new token
                if ($this->save_token) {
                    CloudProviderToken::create([
                        'team_id' => currentTeam()->id,
                        'provider' => 'hetzner',
                        'token' => $this->hetzner_token,
                        'name' => $this->token_name,
                    ]);
                }
            }

            // TODO: Actual Hetzner server provisioning will be implemented in future phase
            // The $hetznerToken variable contains the token to use
            return $this->dispatch('success', 'Hetzner token validated successfully! Server provisioning coming soon.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.server.new.by-hetzner');
    }
}
