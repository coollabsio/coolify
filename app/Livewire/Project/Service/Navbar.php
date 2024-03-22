<?php

namespace App\Livewire\Project\Service;

use App\Actions\Shared\PullImage;
use App\Actions\Service\StartService;
use App\Actions\Service\StopService;
use App\Events\ServiceStatusChanged;
use App\Models\Service;
use Livewire\Component;
use Spatie\Activitylog\Models\Activity;

class Navbar extends Component
{
    public Service $service;
    public array $parameters;
    public array $query;
    public $isDeploymentProgress = false;

    public function getListeners()
    {
        $userId = auth()->user()->id;
        return [
            "echo-private:user.{$userId},ServiceStatusChanged" => 'serviceStarted',
            "serviceStatusChanged"
        ];
    }
    public function serviceStarted() {
        $this->dispatch('success', 'Service status changed.');
    }
    public function serviceStatusChanged()
    {
        $this->dispatch('refresh')->self();
    }
    public function check_status()
    {
        $this->dispatch('check_status');
        $this->dispatch('success', 'Service status updated.');
    }
    public function render()
    {
        return view('livewire.project.service.navbar');
    }
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
    public function start()
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
        if ($forceCleanup) {
            $this->dispatch('success', 'Containers cleaned up.');
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
        StopService::run($this->service);
        $this->service->parse();
        $this->dispatch('imagePulled');
        $activity = StartService::run($this->service);
        $this->dispatch('activityMonitor', $activity->id);
    }
}
