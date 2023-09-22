<?php

namespace App\Http\Livewire\Project\Service;

use App\Actions\Service\StartService;
use App\Actions\Service\StopService;
use App\Jobs\ContainerStatusJob;
use App\Models\Service;
use Illuminate\Support\Collection;
use Livewire\Component;

class Index extends Component
{
    public Service $service;

    public array $parameters;
    public array $query;
    protected $rules = [
        'service.docker_compose_raw' => 'required',
        'service.docker_compose' => 'required',
    ];

    public function mount()
    {
        $this->parameters = get_route_parameters();
        $this->query = request()->query();
        $this->service = Service::whereUuid($this->parameters['service_uuid'])->firstOrFail();
    }
    public function render()
    {
        return view('livewire.project.service.index');
    }
    public function save() {
        $this->service->save();
        $this->service->parse();
        $this->emit('refreshEnvs');
    }

}
