<?php

namespace App\Http\Livewire\Project\Service;

use App\Jobs\ContainerStatusJob;
use App\Models\Service;
use Livewire\Component;

class Index extends Component
{
    public Service $service;
    public $applications;
    public $databases;
    public array $parameters;
    public array $query;
    protected $listeners = ["refreshStacks", "checkStatus"];
    public function render()
    {
        return view('livewire.project.service.index');
    }
    public function mount()
    {
        $this->parameters = get_route_parameters();
        $this->query = request()->query();
        $this->service = Service::whereUuid($this->parameters['service_uuid'])->firstOrFail();
        $this->applications = $this->service->applications->sort();
        $this->databases = $this->service->databases->sort();
    }
    public function checkStatus()
    {
        dispatch(new ContainerStatusJob($this->service->server));
        $this->refreshStacks();
    }
    public function refreshStacks()
    {
        $this->applications = $this->service->applications->sort();
        $this->applications->each(function ($application) {
            $application->refresh();
        });
        $this->databases = $this->service->databases->sort();
        $this->databases->each(function ($database) {
            $database->refresh();
        });
    }
}
