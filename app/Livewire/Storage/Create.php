<?php

namespace App\Livewire\Storage;

use App\Models\S3Storage;
use Illuminate\Support\Uri;
use Livewire\Component;

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
        try {
            if (empty($value)) {
                return;
            }
            if (str($value)->contains('digitaloceanspaces.com')) {
                $uri = Uri::of($value);
                $host = $uri->host();

                if (preg_match('/^(.+)\.([^.]+\.digitaloceanspaces\.com)$/', $host, $matches)) {
                    $host = $matches[2];
                    $value = "https://{$host}";
                }
            }
        } finally {
            if (! str($value)->startsWith('https://') && ! str($value)->startsWith('http://')) {
                $value = 'https://'.$value;
            }
            $this->endpoint = $value;
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
            if (empty($this->endpoint)) {
                $this->storage->endpoint = "https://s3.{$this->region}.amazonaws.com";
            } else {
                $this->storage->endpoint = $this->endpoint;
            }
            $this->storage->team_id = currentTeam()->id;
            $this->storage->testConnection();
            $this->storage->save();

            return redirect()->route('storage.show', $this->storage->uuid);
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to create storage.', $e->getMessage());
            // return handleError($e, $this);
        }
    }
}
