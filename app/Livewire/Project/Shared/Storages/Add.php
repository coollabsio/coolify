<?php

namespace App\Livewire\Project\Shared\Storages;

use App\Models\Application;
use Livewire\Component;

class Add extends Component
{
    public $uuid;
    public $parameters;
    public $isSwarm = false;
    public string $name;
    public string $mount_path;
    public ?string $host_path = null;

    public $rules = [
        'name' => 'required|string',
        'mount_path' => 'required|string',
        'host_path' => 'string|nullable',
    ];

    protected $listeners = ['clearAddStorage' => 'clear'];

    protected $validationAttributes = [
        'name' => 'name',
        'mount_path' => 'mount',
        'host_path' => 'host',
    ];

    public function mount()
    {
        $this->parameters = get_route_parameters();
        if (data_get($this->parameters, 'application_uuid')) {
            $applicationUuid = $this->parameters['application_uuid'];
            $application = Application::where('uuid', $applicationUuid)->first();
            if (!$application) {
                abort(404);
            }
            if ($application->destination->server->isSwarm()) {
                $this->isSwarm = true;
                $this->rules['host_path'] = 'required|string';
            }
        }
    }

    public function submit()
    {
        try {
            $this->validate($this->rules);
            $name = $this->uuid . '-' . $this->name;
            $this->dispatch('addNewVolume', [
                'name' => $name,
                'mount_path' => $this->mount_path,
                'host_path' => $this->host_path,
            ]);
            $this->dispatch('closeStorageModal');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function clear()
    {
        $this->name = '';
        $this->mount_path = '';
        $this->host_path = null;
    }
}
