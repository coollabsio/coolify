<?php

namespace App\Livewire\Security\PrivateKey;

use App\Models\PrivateKey;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Show extends Component
{
    use AuthorizesRequests;

    public PrivateKey $private_key;

    // Explicit properties
    public string $name;

    public ?string $description = null;

    public string $privateKeyValue;

    public bool $isGitRelated = false;

    public $public_key = 'Loading...';

    protected function rules(): array
    {
        return [
            'name' => ValidationPatterns::nameRules(),
            'description' => ValidationPatterns::descriptionRules(),
            'privateKeyValue' => 'required|string',
            'isGitRelated' => 'nullable|boolean',
        ];
    }

    protected function messages(): array
    {
        return array_merge(
            ValidationPatterns::combinedMessages(),
            [
                'name.required' => 'The Name field is required.',
                'name.regex' => 'The Name may only contain letters, numbers, spaces, dashes (-), underscores (_), dots (.), slashes (/), colons (:), and parentheses ().',
                'description.regex' => 'The Description contains invalid characters. Only letters, numbers, spaces, and common punctuation (- _ . : / () \' " , ! ? @ # % & + = [] {} | ~ ` *) are allowed.',
                'privateKeyValue.required' => 'The Private Key field is required.',
                'privateKeyValue.string' => 'The Private Key must be a valid string.',
            ]
        );
    }

    protected $validationAttributes = [
        'name' => 'name',
        'description' => 'description',
        'privateKeyValue' => 'private key',
    ];

    /**
     * Sync data between component properties and model
     *
     * @param  bool  $toModel  If true, sync FROM properties TO model. If false, sync FROM model TO properties.
     */
    private function syncData(bool $toModel = false): void
    {
        if ($toModel) {
            // Sync TO model (before save)
            $this->private_key->name = $this->name;
            $this->private_key->description = $this->description;
            $this->private_key->private_key = $this->privateKeyValue;
            $this->private_key->is_git_related = $this->isGitRelated;
        } else {
            // Sync FROM model (on load/refresh)
            $this->name = $this->private_key->name;
            $this->description = $this->private_key->description;
            $this->privateKeyValue = $this->private_key->private_key;
            $this->isGitRelated = $this->private_key->is_git_related;
        }
    }

    public function mount()
    {
        try {
            $this->private_key = PrivateKey::ownedByCurrentTeam(['name', 'description', 'private_key', 'is_git_related', 'team_id'])->whereUuid(request()->private_key_uuid)->firstOrFail();

            // Explicit authorization check - will throw 403 if not authorized
            $this->authorize('view', $this->private_key);

            $this->syncData(false);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            abort(403, 'You do not have permission to view this private key.');
        } catch (\Throwable) {
            abort(404);
        }
    }

    public function loadPublicKey()
    {
        $this->public_key = $this->private_key->getPublicKey();
        if ($this->public_key === 'Error loading private key') {
            $this->dispatch('error', 'Failed to load public key. The private key may be invalid.');
        }
    }

    public function delete()
    {
        try {
            $this->authorize('delete', $this->private_key);
            $this->private_key->safeDelete();
            currentTeam()->privateKeys = PrivateKey::where('team_id', currentTeam()->id)->get();

            return redirect()->route('security.private-key.index');
        } catch (\Exception $e) {
            $this->dispatch('error', $e->getMessage());
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function changePrivateKey()
    {
        try {
            $this->authorize('update', $this->private_key);

            $this->validate();

            $this->syncData(true);
            $this->private_key->updatePrivateKey([
                'private_key' => formatPrivateKey($this->private_key->private_key),
            ]);
            refresh_server_connection($this->private_key);
            $this->dispatch('success', 'Private key updated.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
