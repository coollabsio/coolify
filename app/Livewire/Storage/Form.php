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

    protected function rules(): array
    {
        return [
            'storage.is_usable' => 'nullable|boolean',
            'storage.name' => ValidationPatterns::nameRules(required: false),
            'storage.description' => ValidationPatterns::descriptionRules(),
            'storage.region' => 'required|max:255',
            'storage.key' => 'required|max:255',
            'storage.secret' => 'required|max:255',
            'storage.bucket' => 'required|max:255',
            'storage.endpoint' => 'required|url|max:255',
        ];
    }

    protected function messages(): array
    {
        return array_merge(
            ValidationPatterns::combinedMessages(),
            [
                'storage.name.regex' => 'The Name may only contain letters, numbers, spaces, dashes (-), underscores (_), dots (.), slashes (/), colons (:), and parentheses ().',
                'storage.description.regex' => 'The Description contains invalid characters. Only letters, numbers, spaces, and common punctuation (- _ . : / () \' " , ! ? @ # % & + = [] {} | ~ ` *) are allowed.',
                'storage.region.required' => 'The Region field is required.',
                'storage.region.max' => 'The Region may not be greater than 255 characters.',
                'storage.key.required' => 'The Access Key field is required.',
                'storage.key.max' => 'The Access Key may not be greater than 255 characters.',
                'storage.secret.required' => 'The Secret Key field is required.',
                'storage.secret.max' => 'The Secret Key may not be greater than 255 characters.',
                'storage.bucket.required' => 'The Bucket field is required.',
                'storage.bucket.max' => 'The Bucket may not be greater than 255 characters.',
                'storage.endpoint.required' => 'The Endpoint field is required.',
                'storage.endpoint.url' => 'The Endpoint must be a valid URL.',
                'storage.endpoint.max' => 'The Endpoint may not be greater than 255 characters.',
            ]
        );
    }

    protected $validationAttributes = [
        'storage.is_usable' => 'Is Usable',
        'storage.name' => 'Name',
        'storage.description' => 'Description',
        'storage.region' => 'Region',
        'storage.key' => 'Key',
        'storage.secret' => 'Secret',
        'storage.bucket' => 'Bucket',
        'storage.endpoint' => 'Endpoint',
    ];

    public function testConnection()
    {
        try {
            $this->authorize('validateConnection', $this->storage);

            $this->storage->testConnection(shouldSave: true);

            return $this->dispatch('success', 'Connection is working.', 'Tested with "ListObjectsV2" action.');
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to create storage.', $e->getMessage());
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
                $this->storage->save();

                // Test connection with new values - if this fails, transaction will rollback
                $this->storage->testConnection(shouldSave: false);

                // If we get here, the connection test succeeded
                $this->storage->is_usable = true;
                $this->storage->unusable_email_sent = false;
                $this->storage->save();
            });

            $this->dispatch('success', 'Storage settings updated and connection verified.');
        } catch (\Throwable $e) {
            // Refresh the model to revert UI to database values after rollback
            $this->storage->refresh();

            return handleError($e, $this);
        }
    }
}
