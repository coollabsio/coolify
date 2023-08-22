<?php

namespace App\Http\Livewire\Team\Storage;

use App\Models\S3Storage;
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
        'name' => 'nullable|min:3|max:255',
        'description' => 'nullable|min:3|max:255',
        'region' => 'required|max:255',
        'key' => 'required|max:255',
        'secret' => 'required|max:255',
        'bucket' => 'required|max:255',
        'endpoint' => 'nullable|url|max:255',
    ];
    protected $validationAttributes = [
        'name' => 'Name',
        'description' => 'Description',
        'region' => 'Region',
        'key' => 'Key',
        'secret' => "Secret",
        'bucket' => 'Bucket',
        'endpoint' => 'Endpoint',
    ];

    public function mount()
    {
        if (is_dev()) {
            $this->name = 'Local MinIO';
            $this->description = 'Local MinIO';
            $this->key = 'minioadmin';
            $this->secret = 'minioadmin';
            $this->bucket = 'local';
            $this->endpoint = 'http://coolify-minio:9000';
        }
    }

    public function submit()
    {
        try {
            $this->validate();
            $this->storage = new S3Storage();
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
            $this->emit('success', 'Connection is working. Tested with "ListObjectsV2" action.');
            $this->storage->save();
            return redirect()->route('team.storages.show', $this->storage->uuid);
        } catch (\Throwable $th) {
            return general_error_handler($th, $this);
        }
    }

    private function test_s3_connection()
    {
        try {
            $this->storage->testConnection();
            return $this->emit('success', 'Connection is working. Tested with "ListObjectsV2" action.');
        } catch (\Throwable $th) {
            return general_error_handler($th, $this);
        }
    }
}
