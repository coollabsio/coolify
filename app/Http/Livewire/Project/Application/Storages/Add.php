<?php

namespace App\Http\Livewire\Project\Application\Storages;

use App\Models\Application;
use App\Models\LocalPersistentVolume;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;
use Livewire\Component;

class Add extends Component
{
    public $parameters;
    public string $name;
    public string $mount_path;
    public string|null $host_path = null;
    protected $rules = [
        'name' => 'required|string',
        'mount_path' => 'required|string',
        'host_path' => 'string|nullable',
    ];
    public function mount()
    {
        $this->parameters = Route::current()->parameters();
    }
    public function submit()
    {
        $this->validate();
        try {
            $application_id = Application::where('uuid', $this->parameters['application_uuid'])->firstOrFail()->id;
            LocalPersistentVolume::create([
                'name' => $this->name,
                'mount_path' => $this->mount_path,
                'host_path' => $this->host_path,
                'resource_id' => $application_id,
                'resource_type' => Application::class,
            ]);
            $this->emit('refreshStorages');
            $this->name = '';
            $this->mount_path = '';
            $this->host_path = '';
        } catch (mixed $e) {
            dd('asdf');
            if ($e instanceof QueryException) {
                dd($e->errorInfo);
                $this->emit('error', $e->errorInfo[2]);
            } else {
                $this->emit('error', $e);
            }
        }
    }
}
