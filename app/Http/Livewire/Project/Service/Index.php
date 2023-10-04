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
    protected $rules = [
        'service.docker_compose_raw' => 'required',
        'service.docker_compose' => 'required',
        'service.name' => 'required',
        'service.description' => 'nullable',
    ];
    protected $listeners = ["saveCompose"];
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
    public function saveCompose($raw)
    {
        $this->service->docker_compose_raw = $raw;
        $this->submit();
    }
    public function checkStatus()
    {
        dispatch_sync(new ContainerStatusJob($this->service->server));
        $this->refreshStack();
    }
    public function refreshStack()
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


    public function submit()
    {
        try {
            $this->validate();
            $this->service->save();
            $this->service->parse();
            $this->service->refresh();
            $this->service->saveComposeConfigs();
            $this->refreshStack();
            $this->emit('refreshEnvs');
            $this->emit('success', 'Service saved successfully.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
