<?php

namespace App\Http\Livewire\Team\Storage;

use App\Models\S3Storage;
use Livewire\Component;

class Form extends Component
{
    public S3Storage $storage;
    protected $rules = [
        'storage.name' => 'nullable|min:3|max:255',
        'storage.description' => 'nullable|min:3|max:255',
        'storage.region' => 'required|max:255',
        'storage.key' => 'required|max:255',
        'storage.secret' => 'required|max:255',
        'storage.bucket' => 'required|max:255',
        'storage.endpoint' => 'required|url|max:255',
    ];
    protected $validationAttributes = [
        'storage.name' => 'Name',
        'storage.description' => 'Description',
        'storage.region' => 'Region',
        'storage.key' => 'Key',
        'storage.secret' => "Secret",
        'storage.bucket' => 'Bucket',
        'storage.endpoint' => 'Endpoint',
    ];

    public function test_s3_connection()
    {
        try {
            $this->storage->testConnection();
            return $this->emit('success', 'Connection is working. Tested with "ListObjectsV2" action.');
        } catch (\Throwable $e) {
            return general_error_handler($e, $this);
        }
    }

    public function delete()
    {
        try {
            $this->storage->delete();
            return redirect()->route('team.storages.all');
        } catch (\Throwable $e) {
            return general_error_handler($e, $this);
        }
    }

    public function submit()
    {
        $this->validate();
        try {
            $this->storage->testConnection();
            $this->emit('success', 'Connection is working. Tested with "ListObjectsV2" action.');
            $this->storage->save();
            $this->emit('success', 'Storage settings saved.');
        } catch (\Throwable $e) {
            return general_error_handler($e, $this);
        }
    }
}
