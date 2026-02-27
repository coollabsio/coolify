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

    // Explicit properties
    public string $name;

    public string $mountPath;

    public ?string $hostPath = null;

    protected $rules = [
        'name' => 'required|string',
        'mountPath' => 'required|string',
        'hostPath' => 'string|nullable',
    ];

    protected $validationAttributes = [
        'name' => 'name',
        'mountPath' => 'mount',
        'hostPath' => 'host',
    ];

    /**
     * Sync data between component properties and model
     *
     * @param  bool  $toModel  If true, sync FROM properties TO model. If false, sync FROM model TO properties.
     */
    private function syncData(bool $toModel = false): void
    {
        $uuidPrefix = $this->resource->uuid . '-';
        if ($toModel) {
            // Sync TO model (before save)
            $nameToSave = $this->name;
            if (!str_starts_with($nameToSave, $uuidPrefix)) {
                $nameToSave = $uuidPrefix . $nameToSave;
            }
            $this->storage->name = $nameToSave;
            $this->storage->mount_path = $this->mountPath;
            $this->storage->host_path = $this->hostPath;
        } else {
            // Sync FROM model (on load/refresh)
            $this->name = $this->storage->name;
            if (str_starts_with($this->name, $uuidPrefix)) {
                $this->name = substr($this->name, strlen($uuidPrefix));
            }
            $this->mountPath = $this->storage->mount_path;
            $this->hostPath = $this->storage->host_path;
        }
    }

    public function mount()
    {
        $this->syncData(false);
        $this->isReadOnly = $this->storage->isReadOnlyVolume();
    }

    public function submit()
    {
        $this->authorize('update', $this->resource);

        $this->validate();
        $this->syncData(true);
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
