<?php

namespace App\Livewire\Storage;

use App\Models\S3Storage;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
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

    protected function rules(): array
    {
        return [
            'isUsable' => 'nullable|boolean',
            'name' => ValidationPatterns::nameRules(required: false),
            'description' => ValidationPatterns::descriptionRules(),
            'region' => 'required|max:255',
            'key' => 'required|max:255',
            'secret' => 'required|max:255',
            'bucket' => 'required|max:255',
            'endpoint' => 'required|url|max:255',
        ];
    }

    protected function messages(): array
    {
        return array_merge(
            ValidationPatterns::combinedMessages(),
            [
                'name.regex' => 'The Name may only contain letters, numbers, spaces, dashes (-), underscores (_), dots (.), slashes (/), colons (:), and parentheses ().',
                'description.regex' => 'The Description contains invalid characters. Only letters, numbers, spaces, and common punctuation (- _ . : / () \' " , ! ? @ # % & + = [] {} | ~ ` *) are allowed.',
                'region.required' => 'The Region field is required.',
                'region.max' => 'The Region may not be greater than 255 characters.',
                'key.required' => 'The Access Key field is required.',
                'key.max' => 'The Access Key may not be greater than 255 characters.',
                'secret.required' => 'The Secret Key field is required.',
                'secret.max' => 'The Secret Key may not be greater than 255 characters.',
                'bucket.required' => 'The Bucket field is required.',
                'bucket.max' => 'The Bucket may not be greater than 255 characters.',
                'endpoint.required' => 'The Endpoint field is required.',
                'endpoint.url' => 'The Endpoint must be a valid URL.',
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
    }

    public function testConnection()
    {
        try {
            $this->authorize('validateConnection', $this->storage);

            $this->storage->testConnection(shouldSave: true);

            // Update component property to reflect the new validation status
            $this->isUsable = $this->storage->is_usable;

            return $this->dispatch('success', 'Connection is working.', 'Tested with "ListObjectsV2" action.');
        } catch (\Throwable $e) {
            // Refresh model and sync to get the latest state
            $this->storage->refresh();
            $this->isUsable = $this->storage->is_usable;

            $this->dispatch('error', 'Failed to test connection.', $e->getMessage());
        }
    }

    public function delete()
    {
        try {
            $this->authorize('delete', $this->storage);

            $this->storage->delete();

            return redirect()->route('storage.index');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

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
