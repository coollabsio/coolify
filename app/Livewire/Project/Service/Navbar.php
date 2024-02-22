<?php

namespace App\Livewire\Project\Service;

use App\Actions\Shared\PullImage;
use App\Actions\Service\StartService;
use App\Actions\Service\StopService;
use App\Events\ServiceStatusChanged;
use App\Jobs\ContainerStatusJob;
use App\Models\Service;
use Livewire\Component;
use Spatie\Activitylog\Models\Activity;

class Navbar extends Component
{
    public Service $service;
    public array $parameters;
    public array $query;
    public $isDeploymentProgress = false;

    public function checkDeployments()
    {
        $activity = Activity::where('properties->type_uuid', $this->service->uuid)->latest()->first();
        $status = data_get($activity, 'properties.status');
        if ($status === 'queued' || $status === 'in_progress') {
            $this->isDeploymentProgress = true;
        } else {
            $this->isDeploymentProgress = false;
        }
    }
    public function getListeners()
    {
        return [
            "serviceStatusChanged"
        ];
    }
    public function serviceStatusChanged()
    {
        $this->service->refresh();
    }
    public function render()
    {
        return view('livewire.project.service.navbar');
    }
    public function check_status($showNotification = false)
    {
        dispatch_sync(new ContainerStatusJob($this->service->destination->server));
        $this->service->refresh();
        if ($showNotification) $this->dispatch('success', 'Service status updated.');
    }
    public function deploy()
    {
        $this->checkDeployments();
        if ($this->isDeploymentProgress) {
            $this->dispatch('error', 'There is a deployment in progress.');
            return;
        }
        $this->service->parse();
        $activity = StartService::run($this->service);
        $this->dispatch('activityMonitor', $activity->id);
    }
    public function stop(bool $forceCleanup = false)
    {
        StopService::run($this->service);
        $this->service->refresh();
        if ($forceCleanup) {
            $this->dispatch('success', 'Force cleanup service.');
        } else {
            $this->dispatch('success', 'Service stopped.');
        }
        ServiceStatusChanged::dispatch();
    }
    public function restart()
    {
        $this->checkDeployments();
        if ($this->isDeploymentProgress) {
            $this->dispatch('error', 'There is a deployment in progress.');
            return;
        }
        PullImage::run($this->service);
        $this->dispatch('image-pulled');
        StopService::run($this->service);
        $this->service->parse();
        $activity = StartService::run($this->service);
        $this->dispatch('activityMonitor', $activity->id);
    }
}
