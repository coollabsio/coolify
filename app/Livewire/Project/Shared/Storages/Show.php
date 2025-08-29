<?php

namespace App\Livewire\Project\Shared\Storages;

use App\Models\InstanceSettings;
use App\Models\LocalPersistentVolume;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Component;

class Show extends Component
{
    use AuthorizesRequests;

    public LocalPersistentVolume $storage;

    public $resource;

    public bool $isReadOnly = false;

    public bool $isFirst = true;

    public bool $isService = false;

    public ?string $startedAt = null;

    protected $rules = [
        'storage.name' => 'required|string',
        'storage.mount_path' => 'required|string',
        'storage.host_path' => 'string|nullable',
    ];

    protected $validationAttributes = [
        'name' => 'name',
        'mount_path' => 'mount',
        'host_path' => 'host',
    ];

    public function submit()
    {
        $this->authorize('update', $this->resource);

        $this->validate();
        $this->storage->save();
        $this->dispatch('success', 'Storage updated successfully');
    }

    public function delete($password)
    {
        $this->authorize('update', $this->resource);

        if (! data_get(InstanceSettings::get(), 'disable_two_step_confirmation')) {
            if (! Hash::check($password, Auth::user()->password)) {
                $this->addError('password', 'The provided password is incorrect.');

                return;
            }
        }

        $this->storage->delete();
        $this->dispatch('refreshStorages');
    }
}
