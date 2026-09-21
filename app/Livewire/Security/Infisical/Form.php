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
    public string $infisical_project_id = '';

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

    /**
     * Note: `name`, `host` and `infisical_project_id` are validated twice -
     * once by the `#[Validate]` attributes above and again by the identical
     * entries here. That is harmless (both express the same constraint, and
     * this method is what `validate()` consults), but it is recorded rather
     * than tidied away because neither half is safe to delete blindly: the
     * attributes drive Livewire's real-time per-field validation, while this
     * method is the only place the create-vs-edit credential rules can live.
     * The credential handling in this component is deliberate - see the
     * property docblocks - and must not be "simplified".
     */
    protected function rules(): array
    {
        $requiredOnCreate = $this->connection ? 'nullable' : 'required';

        $rules = [
            'name' => ['required', 'string', 'max:128'],
            'host' => ['required', 'url', 'max:255'],
            'infisical_project_id' => ['required', 'string', 'max:255'],
            'client_id' => [$requiredOnCreate, 'string', 'max:255'],
            'client_secret' => [$requiredOnCreate, 'string', 'max:512'],
        ];

        // On create there is no stored credential to fall back to, so a
        // whitespace-only value (which satisfies `required` but not
        // `trim() !== ''`) must be rejected outright rather than silently
        // creating a connection with a blank secret. On edit, a
        // whitespace-only value is instead treated as "leave unchanged" in
        // submit() - see the trim() guards there.
        if ($this->connection === null) {
            $notBlank = function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && trim($value) === '') {
                    $fail('The '.str($attribute)->replace('_', ' ')->title().' field must not be blank.');
                }
            };

            $rules['client_id'][] = $notBlank;
            $rules['client_secret'][] = $notBlank;
        }

        return $rules;
    }

    protected function messages(): array
    {
        return [
            'name.required' => 'The Name field is required.',
            'host.required' => 'The Host field is required.',
            'host.url' => 'The Host must be a valid URL.',
            'infisical_project_id.required' => 'The Infisical Project ID field is required.',
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
            $this->connection->infisical_project_id = $this->infisical_project_id;
        } else {
            $this->name = $this->connection->name;
            $this->host = $this->connection->host;
            $this->infisical_project_id = $this->connection->infisical_project_id;
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

            $trimmedClientId = trim($this->client_id);
            $trimmedClientSecret = trim($this->client_secret);

            // Blank (or whitespace-only) credential fields mean "leave
            // unchanged" - never overwrite a stored secret with an empty
            // value. Guarding on trim() rather than the raw string matters:
            // InfisicalConnection::boot()'s `saving` hook trims
            // client_id/client_secret before persisting, so a
            // whitespace-only submission that slipped past a `!== ''` guard
            // would still be trimmed down to '' and silently wipe the
            // stored credential.
            if ($trimmedClientId !== '') {
                $this->connection->client_id = $trimmedClientId;
            }
            if ($trimmedClientSecret !== '') {
                $this->connection->client_secret = $trimmedClientSecret;
            }

            $this->connection->save();
        } else {
            $this->connection = InfisicalConnection::create([
                'team_id' => currentTeam()->id,
                'name' => $this->name,
                'host' => $this->host,
                'infisical_project_id' => $this->infisical_project_id,
                'client_id' => trim($this->client_id),
                'client_secret' => trim($this->client_secret),
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
