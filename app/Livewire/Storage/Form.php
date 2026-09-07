<?php

namespace App\Livewire\Storage;

use App\Models\S3Storage;
use App\Rules\SafeWebhookUrl;
use App\Rules\ValidS3BucketName;
use App\Support\DomainUrlParts;
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

    public array $endpointParts = ['scheme' => 'https', 'host' => '', 'port' => '', 'path' => ''];

    public bool $endpointPartsChanged = false;

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
            $this->endpointParts = DomainUrlParts::split($this->endpoint);
            $this->endpointPartsChanged = false;
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

    public function testConnection()
    {
        $testedStorage = null;

        try {
            $this->authorize('validateConnection', $this->storage);
            if ($this->endpointPartsChanged) {
                $this->endpoint = DomainUrlParts::compose(...$this->endpointParts);
            }
            $testedStorage = new S3Storage;
            $testedStorage->uuid = $this->storage->uuid;
            $testedStorage->team_id = $this->storage->team_id;
            $testedStorage->unusable_email_sent = $this->storage->unusable_email_sent;
            $testedStorage->name = $this->name;
            $testedStorage->description = $this->description;
            $testedStorage->endpoint = $this->endpoint;
            $testedStorage->bucket = $this->bucket;
            $testedStorage->region = $this->region;
            $testedStorage->key = $this->key;
            $testedStorage->secret = $this->secret;

            $testedStorage->testConnection();

            // Update component property to reflect the new validation status
            $this->isUsable = $testedStorage->is_usable;
            $this->storage->is_usable = $testedStorage->is_usable;
            $this->storage->unusable_email_sent = $testedStorage->unusable_email_sent;
            $this->storage->save();
            $this->dispatch('storage-status-changed', isUsable: $this->isUsable);

            return $this->dispatch('success', 'Connection is working.', 'Tested with "ListObjectsV2" action.');
        } catch (\Throwable $e) {
            if ($testedStorage) {
                $this->isUsable = $testedStorage->is_usable;
                $this->storage->is_usable = $testedStorage->is_usable;
                $this->storage->unusable_email_sent = $testedStorage->unusable_email_sent;
                $this->storage->save();
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
            if ($this->endpointPartsChanged) {
                $this->endpoint = DomainUrlParts::compose(...$this->endpointParts);
            }

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

    public function updatedEndpointParts(): void
    {
        $this->endpointPartsChanged = true;
    }
}
