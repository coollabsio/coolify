<?php

namespace App\Http\Livewire\Project\Service;

use App\Models\Service;
use Livewire\Component;

class Index extends Component
{
    public Service $service;

    public array $parameters;
    public array $query;
    protected $rules = [
        'service.docker_compose_raw' => 'required',
        'service.docker_compose' => 'required',
        'service.name' => 'required',
        'service.description' => 'required',
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
        $this->service->refresh();
        $this->emit('refreshEnvs');
    }
    public function submit() {
        try {
            $this->validate();
            $this->service->save();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

}
