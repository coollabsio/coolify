<?php

namespace App\Http\Livewire\Project\Service;

use App\Models\Service;
use App\Models\ServiceApplication;
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

    public function mount()
    {
        $this->parameters = get_route_parameters();
        $this->query = request()->query();
        $this->service = Service::whereUuid($this->parameters['service_uuid'])->firstOrFail();
        $this->applications = $this->service->applications->sort();
        $this->databases = $this->service->databases->sort();

    }
    public function render()
    {
        return view('livewire.project.service.index');
    }
    public function save()
    {
        try {
            $this->service->save();
            $this->service->parse();
            $this->service->refresh();
            $this->emit('refreshEnvs');
            $this->emit('success', 'Service saved successfully.');
            $this->service->saveComposeConfigs();
        } catch(\Throwable $e) {
            return handleError($e, $this);
        }
    }
    public function submit()
    {
        try {
            $this->validate();
            $this->service->save();
            $this->emit('success', 'Service saved successfully.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
