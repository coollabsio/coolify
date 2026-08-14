<?php

namespace App\Livewire\Storage;

use App\Models\S3Storage;
use App\Rules\SafeWebhookUrl;
use App\Rules\ValidS3BucketName;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Component;

class Form extends Component
{
    use AuthorizesRequests;

    public S3Storage $storage;

    // Explicit properties
    public ?string $name = null;

    public ?string $description = null;

    public string $endpoint;

    public string $bucket;

    public string $region;

    public string $key;

    public string $secret;

    public ?bool $isUsable = null;

    public bool $isPasswordHiddenForMember = false;

    protected function rules(): array
    {
        return [
            'isUsable' => 'nullable|boolean',
            'name' => ValidationPatterns::nameRules(required: false),
            'description' => ValidationPatterns::descriptionRules(),
            'region' => 'required|max:255',
            'key' => 'required|max:255',
            'secret' => 'required|max:255',
            'bucket' => ['required', new ValidS3BucketName],
            'endpoint' => ['required', 'max:255', new SafeWebhookUrl],
        ];
    }

    protected function messages(): array
    {
        return array_merge(
            ValidationPatterns::combinedMessages(),
            [
                'region.required' => 'The Region field is required.',
                'region.max' => 'The Region may not be greater than 255 characters.',
                'key.required' => 'The Access Key field is required.',
                'key.max' => 'The Access Key may not be greater than 255 characters.',
                'secret.required' => 'The Secret Key field is required.',
                'secret.max' => 'The Secret Key may not be greater than 255 characters.',
                'bucket.required' => 'The Bucket field is required.',
                'endpoint.required' => 'The Endpoint field is required.',
                'endpoint.max' => 'The Endpoint may not be greater than 255 characters.',
            ]
        );
    }

    protected $validationAttributes = [
        'isUsable' => 'Is Usable',
        'name' => 'Name',
        'description' => 'Description',
        'region' => 'Region',
        'key' => 'Key',
        'secret' => 'Secret',
        'bucket' => 'Bucket',
        'endpoint' => 'Endpoint',
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
            $this->storage->name = $this->name;
            $this->storage->description = $this->description;
            $this->storage->endpoint = $this->endpoint;
            $this->storage->bucket = $this->bucket;
            $this->storage->region = $this->region;
            $this->storage->key = $this->key;
            $this->storage->secret = $this->secret;
            $this->storage->is_usable = $this->isUsable;
        } else {
            // Sync FROM model (on load/refresh)
            $this->name = $this->storage->name;
            $this->description = $this->storage->description;
            $this->endpoint = $this->storage->endpoint;
            $this->bucket = $this->storage->bucket;
            $this->region = $this->storage->region;
            $this->key = $this->storage->key;
            $this->secret = $this->storage->secret;
            $this->isUsable = $this->storage->is_usable;
        }
    }

    public function mount()
    {
        $this->syncData(false);

        $this->isPasswordHiddenForMember = auth()->user()?->isMember() ?? false;
        if ($this->isPasswordHiddenForMember) {
            $this->key = '';
            $this->secret = '';
        }
    }

    /**
     * The values currently shown in the form, i.e. what the user is looking at.
     * Key/secret fall back to the saved storage for members, since those fields
     * are hidden (and blanked out) in the form for them - see mount().
     */
    private function formValues(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'endpoint' => $this->endpoint,
            'bucket' => $this->bucket,
            'region' => $this->region,
            'key' => $this->isPasswordHiddenForMember ? $this->storage->key : $this->key,
            'secret' => $this->isPasswordHiddenForMember ? $this->storage->secret : $this->secret,
        ];
    }

    /**
     * Whether the form is still identical to the saved storage, i.e. there are
     * no unsaved changes to test.
     */
    private function formMatchesSavedStorage(array $formValues): bool
    {
        foreach ($formValues as $attribute => $value) {
            if ($this->storage->{$attribute} !== $value) {
                return false;
            }
        }

        return true;
    }

    public function testConnection()
    {
        try {
            $this->authorize('validateConnection', $this->storage);

            $formValues = $this->formValues();

            if ($this->formMatchesSavedStorage($formValues)) {
                // No unsaved changes: test (and persist the outcome on) the saved
                // storage, exactly as before.
                $this->storage->testConnection(shouldSave: true);
            } else {
                // There are unsaved changes: test what's actually in the form
                // instead of the stale saved values. Use a throwaway copy of the
                // model so a passing or failing test never persists credentials
                // or settings the user hasn't saved yet.
                $unsavedStorage = $this->storage->replicate();
                $unsavedStorage->name = $formValues['name'];
                $unsavedStorage->description = $formValues['description'];
                $unsavedStorage->endpoint = $formValues['endpoint'];
                $unsavedStorage->bucket = $formValues['bucket'];
                $unsavedStorage->region = $formValues['region'];
                $unsavedStorage->key = $formValues['key'];
                $unsavedStorage->secret = $formValues['secret'];

                $unsavedStorage->testConnection(shouldSave: false);
            }

            // Update component property to reflect the new validation status
            $this->isUsable = true;
            $this->dispatch('storage-status-changed', isUsable: $this->isUsable);

            return $this->dispatch('success', 'Connection is working.', 'Tested with "ListObjectsV2" action.');
        } catch (\Throwable $e) {
            if ($this->formMatchesSavedStorage($this->formValues())) {
                // Refresh model and sync to get the latest state
                $this->storage->refresh();
                $this->isUsable = $this->storage->is_usable;
            } else {
                // Unsaved changes failed the test; nothing was persisted above.
                $this->isUsable = false;
            }
            $this->dispatch('storage-status-changed', isUsable: $this->isUsable);

            $this->dispatch('error', 'Failed to test connection.', $e->getMessage());
        }
    }

    #[On('submitStorage')]
    public function submit()
    {
        try {
            $this->authorize('update', $this->storage);

            DB::transaction(function () {
                $this->validate();

                // Sync properties to model before saving
                $this->syncData(true);
                $this->storage->save();

                // Test connection with new values - if this fails, transaction will rollback
                $this->storage->testConnection(shouldSave: false);

                // If we get here, the connection test succeeded
                $this->storage->is_usable = true;
                $this->storage->unusable_email_sent = false;
                $this->storage->save();

                // Update local property to reflect success
                $this->isUsable = true;
            });

            $this->dispatch('success', 'Storage settings updated and connection verified.');
        } catch (\Throwable $e) {
            // Refresh the model to revert UI to database values after rollback
            $this->storage->refresh();
            $this->syncData(false);

            return handleError($e, $this);
        }
    }
}
