<?php

namespace App\Http\Livewire\Project\Service;

use App\Models\Service;
use Livewire\Component;

class Index extends Component
{
    public Service $service;

    public array $parameters;
    public array $query;

    public function mount() {
        $this->parameters = get_route_parameters();
        $this->query = request()->query();
        $this->service = Service::whereUuid($this->parameters['service_uuid'])->firstOrFail();
    }
    public function render()
    {
        return view('livewire.project.service.index')->layout('layouts.app');
    }
}
