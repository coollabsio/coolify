<?php

namespace App\Livewire\Project\Service;

use App\Jobs\ContainerStatusJob;
use App\Models\Service;
use Livewire\Component;

class Configuration extends Component
{
    public Service $service;
    public $applications;
    public $databases;
    public array $parameters;
    public array $query;
    public function getListeners()
    {
        $userId = auth()->user()->id;
        return [
            "echo-private:user.{$userId},ServiceStatusChanged" => 'checkStatus',
            "refreshStacks",
            "checkStatus",
        ];
    }
    public function render()
    {
        return view('livewire.project.service.configuration');
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
        dispatch_sync(new ContainerStatusJob($this->service->server));
        $this->refreshStacks();
        $this->dispatch('serviceStatusChanged');
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
