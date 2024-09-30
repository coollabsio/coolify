<?php

namespace App\Livewire\Storage;

use App\Models\S3Storage;
use Livewire\Component;

class Form extends Component
{
    public S3Storage $storage;

    protected $rules = [
        'storage.is_usable' => 'nullable|boolean',
        'storage.name' => 'nullable|min:3|max:255',
        'storage.description' => 'nullable|min:3|max:255',
        'storage.region' => 'required|max:255',
        'storage.key' => 'required|max:255',
        'storage.secret' => 'required|max:255',
        'storage.bucket' => 'required|max:255',
        'storage.endpoint' => 'required|url|max:255',
    ];

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

    public function test_s3_connection()
    {
        try {
            $this->storage->testConnection(shouldSave: true);

            return $this->dispatch('success', 'Connection is working.', 'Tested with "ListObjectsV2" action.');
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to create storage.', $e->getMessage());
        }
    }

    public function delete()
    {
        try {
            $this->storage->delete();

            return redirect()->route('storage.index');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function submit()
    {
        $this->validate();
        try {
            $this->test_s3_connection();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
