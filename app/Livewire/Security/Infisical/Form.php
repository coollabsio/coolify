<?php

namespace App\Livewire\Security\Infisical;

use App\Models\InfisicalConnection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Form extends Component
{
    use AuthorizesRequests;

    public ?InfisicalConnection $connection = null;

    #[Validate(['required', 'string', 'max:128'])]
    public string $name = '';

    #[Validate(['required', 'url', 'max:255'])]
    public string $host = 'https://app.infisical.com';

    /**
     * Never repopulated from the model: `client_id`/`client_secret` are
     * `encrypted`-cast, so setting them from `$this->connection` would put
     * the decrypted value into this component's public state - and Livewire
     * serialises public properties into `wire:snapshot`, landing the
     * decrypted secret in the page HTML on every edit-form mount. Left
     * blank on edit; `rules()` makes both required only on create, and
     * `submit()` only overwrites the stored value when a new one is typed.
     */
    public string $client_id = '';

    public string $client_secret = '';

    public bool $isPasswordHiddenForMember = false;

    protected function rules(): array
    {
        $requiredOnCreate = $this->connection ? 'nullable' : 'required';

        return [
            'name' => ['required', 'string', 'max:128'],
            'host' => ['required', 'url', 'max:255'],
            'client_id' => [$requiredOnCreate, 'string', 'max:255'],
            'client_secret' => [$requiredOnCreate, 'string', 'max:512'],
        ];
    }

    protected function messages(): array
    {
        return [
            'name.required' => 'The Name field is required.',
            'host.required' => 'The Host field is required.',
            'host.url' => 'The Host must be a valid URL.',
            'client_id.required' => 'The Client ID field is required.',
            'client_secret.required' => 'The Client Secret field is required.',
        ];
    }

    /**
     * Sync non-secret data between component properties and model. Secrets
     * are never pulled from the model into properties (see property
     * docblocks above) and are applied to the model separately in
     * `submit()`, only when a new value was typed.
     *
     * @param  bool  $toModel  If true, sync FROM properties TO model. If false, sync FROM model TO properties.
     */
    private function syncData(bool $toModel = false): void
    {
        if ($this->connection === null) {
            return;
        }

        if ($toModel) {
            $this->connection->name = $this->name;
            $this->connection->host = $this->host;
        } else {
            $this->name = $this->connection->name;
            $this->host = $this->connection->host;
        }
    }

    public function mount(): void
    {
        $this->authorize('viewAny', InfisicalConnection::class);

        $this->syncData(false);

        $this->isPasswordHiddenForMember = auth()->user()?->isMember() ?? false;
    }

    public function submit(): void
    {
        $this->authorize($this->connection ? 'update' : 'create', $this->connection ?? InfisicalConnection::class);
        $this->validate();

        if ($this->connection) {
            $this->syncData(true);

            // Blank credential fields mean "leave unchanged" - never
            // overwrite a stored secret with an empty string.
            if ($this->client_id !== '') {
                $this->connection->client_id = $this->client_id;
            }
            if ($this->client_secret !== '') {
                $this->connection->client_secret = $this->client_secret;
            }

            $this->connection->save();
        } else {
            $this->connection = InfisicalConnection::create([
                'team_id' => currentTeam()->id,
                'name' => $this->name,
                'host' => $this->host,
                'client_id' => $this->client_id,
                'client_secret' => $this->client_secret,
            ]);
        }

        // Never leave the submitted secret values sitting in this
        // component's public state (and thus the next wire:snapshot) after
        // save.
        $this->client_id = '';
        $this->client_secret = '';

        $this->dispatch('success', 'Infisical connection saved.');
        $this->dispatch('infisicalConnectionSaved');
    }

    public function render()
    {
        return view('livewire.security.infisical.form');
    }
}
