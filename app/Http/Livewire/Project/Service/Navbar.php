<?php

namespace App\Http\Livewire\Project\Service;

use App\Actions\Service\StartService;
use App\Actions\Service\StopService;
use App\Models\Service;
use Livewire\Component;

class Navbar extends Component
{
    public Service $service;
    public array $parameters;
    public array $query;
    protected $listeners = ["checkStatus"];

    public function render()
    {
        return view('livewire.project.service.navbar');
    }
    public function checkStatus() {
        $this->service->refresh();
    }
    public function deploy()
    {
        $this->service->parse();
        $activity = StartService::run($this->service);
        $this->emit('newMonitorActivity', $activity->id);
    }
    public function stop(bool $forceCleanup = false)
    {
        StopService::run($this->service);
        $this->service->refresh();
        if ($forceCleanup) {
            $this->emit('success', 'Force cleanup service successfully.');
        } else {
            $this->emit('success', 'Service stopped successfully.');
        }
        $this->emit('checkStatus');
    }
}
