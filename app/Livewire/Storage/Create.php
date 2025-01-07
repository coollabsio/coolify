<?php

namespace App\Livewire\Storage;

use App\Models\S3Storage;
use Livewire\Component;
use Throwable;

class Create extends Component
{
    public string $name;

    public string $description;

    public string $region = 'us-east-1';

    public string $key;

    public string $secret;

    public string $bucket;

    public string $endpoint;

    public S3Storage $storage;

    protected $rules = [
        'name' => 'required|min:3|max:255',
        'description' => 'nullable|min:3|max:255',
        'region' => 'required|max:255',
        'key' => 'required|max:255',
        'secret' => 'required|max:255',
        'bucket' => 'required|max:255',
        'endpoint' => 'required|url|max:255',
    ];

    protected $validationAttributes = [
        'name' => 'Name',
        'description' => 'Description',
        'region' => 'Region',
        'key' => 'Key',
        'secret' => 'Secret',
        'bucket' => 'Bucket',
        'endpoint' => 'Endpoint',
    ];

    public function updatedEndpoint($value)
    {
        if (! str($value)->startsWith('https://') && ! str($value)->startsWith('http://')) {
            $this->endpoint = 'https://'.$value;
            $value = $this->endpoint;
        }

        if (str($value)->contains('your-objectstorage.com') && ! isset($this->bucket)) {
            $this->bucket = str($value)->after('//')->before('.');
        } elseif (str($value)->contains('your-objectstorage.com')) {
            $this->bucket = $this->bucket ?: str($value)->after('//')->before('.');
        }
    }

    public function submit()
    {
        try {
            $this->validate();
            $this->storage = new S3Storage;
            $this->storage->name = $this->name;
            $this->storage->description = $this->description ?? null;
            $this->storage->region = $this->region;
            $this->storage->key = $this->key;
            $this->storage->secret = $this->secret;
            $this->storage->bucket = $this->bucket;
            $this->storage->endpoint = ! isset($this->endpoint) || ($this->endpoint === '' || $this->endpoint === '0') ? "https://s3.{$this->region}.amazonaws.com" : $this->endpoint;
            $this->storage->team_id = currentTeam()->id;
            $this->storage->testConnection();
            $this->storage->save();

            return redirect()->route('storage.show', $this->storage->uuid);
        } catch (Throwable $e) {
            $this->dispatch('error', 'Failed to create storage.', $e->getMessage());
            // return handleError($e, $this);
        }

        return null;
    }
}
