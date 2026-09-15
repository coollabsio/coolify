<?php

namespace App\Livewire\Security\AgeKey;

use App\Models\AgeKey;
use App\Support\ValidationPatterns;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Show extends Component
{
    use AuthorizesRequests;

    public AgeKey $age_key;

    public bool $modalMode = false;

    public string $name;

    public ?string $description = null;

    public string $publicKeyValue;

    public bool $isInUse = false;

    public string $deleteDisabledReason = 'This age key is currently used by a backup schedule and cannot be deleted.';

    protected function rules(): array
    {
        return [
            'name' => ValidationPatterns::nameRules(),
            'description' => ValidationPatterns::descriptionRules(),
            'publicKeyValue' => 'required|string',
        ];
    }

    protected function messages(): array
    {
        return array_merge(
            ValidationPatterns::combinedMessages(),
            [
                'name.required' => 'The Name field is required.',
                'publicKeyValue.required' => 'The Public Key field is required.',
            ]
        );
    }

    protected $validationAttributes = [
        'name' => 'name',
        'description' => 'description',
        'publicKeyValue' => 'public key',
    ];

    private function syncData(bool $toModel = false): void
    {
        if ($toModel) {
            $this->age_key->name = $this->name;
            $this->age_key->description = $this->description;
            $this->age_key->public_key = $this->publicKeyValue;
        } else {
            $this->name = $this->age_key->name;
            $this->description = $this->age_key->description;
            $this->publicKeyValue = $this->age_key->public_key;
        }
    }

    public function mount(?string $age_key_uuid = null, bool $modalMode = false)
    {
        $this->modalMode = $modalMode;
        try {
            $this->age_key = AgeKey::ownedByCurrentTeam(['name', 'description', 'public_key', 'team_id'])
                ->whereUuid($age_key_uuid ?? request()->age_key_uuid)
                ->firstOrFail();

            $this->authorize('view', $this->age_key);

            $this->syncData(false);
            $this->isInUse = $this->age_key->isInUse();
        } catch (AuthorizationException $e) {
            abort(403, 'You do not have permission to view this age key.');
        } catch (\Throwable) {
            abort(404);
        }
    }

    public function delete()
    {
        try {
            $this->authorize('delete', $this->age_key);

            if ($this->age_key->isInUse()) {
                $this->isInUse = true;
                $this->dispatch('error', $this->deleteDisabledReason);

                return;
            }

            $this->age_key->delete();

            if ($this->modalMode) {
                $this->dispatch('ageKeyDeleted');

                return null;
            }

            return redirectRoute($this, 'security.age-key.index');
        } catch (\Exception $e) {
            $this->dispatch('error', $e->getMessage());
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function changeAgeKey()
    {
        try {
            $this->authorize('update', $this->age_key);

            $this->validate();

            $this->syncData(true);
            $this->age_key->save();

            $this->dispatch('success', 'Age key updated.');
            if ($this->modalMode) {
                $this->dispatch('ageKeyUpdated');

                return null;
            }
            $this->dispatch('securityResourceChanged');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
