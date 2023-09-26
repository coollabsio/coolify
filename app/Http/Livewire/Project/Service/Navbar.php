<?php

namespace App\Http\Livewire\Project\Service;

use App\Actions\Service\StartService;
use App\Actions\Service\StopService;
use App\Jobs\ContainerStatusJob;
use App\Models\Service;
use Livewire\Component;

class Navbar extends Component
{
    public Service $service;
    public array $parameters;
    public array $query;
    protected $listeners = ['serviceStatusUpdated'];

    public function render()
    {
        return view('livewire.project.service.navbar');
    }
    public function serviceStatusUpdated()
    {
        ray('serviceStatusUpdated');
        $this->check_status();
    }
    public function check_status()
    {
        dispatch_sync(new ContainerStatusJob($this->service->server));
        $this->service->refresh();
    }
    public function deploy()
    {
        $this->service->parse();
        $activity = StartService::run($this->service);
        $this->emit('newMonitorActivity', $activity->id);
    }
    public function stop()
    {
        StopService::run($this->service);
        $this->service->refresh();
        $this->emit('success', 'Service stopped successfully.');
    }
}
