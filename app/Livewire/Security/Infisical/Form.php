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

    #[Validate(['required', 'string', 'max:255'])]
    public string $client_id = '';

    #[Validate(['required', 'string', 'max:512'])]
    public string $client_secret = '';

    public bool $isPasswordHiddenForMember = false;

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
     * Sync data between component properties and model.
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
            $this->connection->client_id = $this->client_id;
            $this->connection->client_secret = $this->client_secret;
        } else {
            $this->name = $this->connection->name;
            $this->host = $this->connection->host;
            $this->client_id = $this->connection->client_id;
            $this->client_secret = $this->connection->client_secret;
        }
    }

    public function mount(): void
    {
        $this->authorize('viewAny', InfisicalConnection::class);

        $this->syncData(false);

        $this->isPasswordHiddenForMember = auth()->user()?->isMember() ?? false;

        if ($this->isPasswordHiddenForMember) {
            $this->client_id = '';
            $this->client_secret = '';
        }
    }

    public function submit(): void
    {
        $this->authorize($this->connection ? 'update' : 'create', $this->connection ?? InfisicalConnection::class);
        $this->validate();

        if ($this->connection) {
            $this->syncData(true);
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

        $this->dispatch('success', 'Infisical connection saved.');
        $this->dispatch('infisicalConnectionSaved');
    }

    public function render()
    {
        return view('livewire.security.infisical.form');
    }
}
