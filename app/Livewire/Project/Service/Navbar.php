<?php

namespace App\Livewire\Project\Service;

use App\Actions\Service\StartService;
use App\Actions\Service\StopService;
use App\Actions\Shared\PullImage;
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

    public $docker_cleanup = true;

    public $title = 'Configuration';

    public function mount()
    {
        if (str($this->service->status())->contains('running') && is_null($this->service->config_hash)) {
            ray('isConfigurationChanged init');
            $this->service->isConfigurationChanged(true);
            $this->dispatch('configurationChanged');
        }
    }

    public function getListeners()
    {
        $userId = auth()->user()->id;

        return [
            "echo-private:user.{$userId},ServiceStatusChanged" => 'serviceStarted',
            "envsUpdated" => '$refresh',
        ];
    }

    public function serviceStarted()
    {
        // $this->dispatch('success', 'Service status changed.');
        if (is_null($this->service->config_hash) || $this->service->isConfigurationChanged()) {
            $this->service->isConfigurationChanged(true);
            $this->dispatch('configurationChanged');
        } else {
            $this->dispatch('configurationChanged');
        }
    }

    public function check_status_without_notification()
    {
        $this->dispatch('check_status');
    }

    public function check_status()
    {
        $this->dispatch('check_status');
        $this->dispatch('success', 'Service status updated.');
    }

    public function checkDeployments()
    {
        try {
            // TODO: This is a temporary solution. We need to refactor this.
            // We need to delete null bytes somehow.
            $activity = Activity::where('properties->type_uuid', $this->service->uuid)->latest()->first();
            $status = data_get($activity, 'properties.status');
            if ($status === 'queued' || $status === 'in_progress') {
                $this->isDeploymentProgress = true;
            } else {
                $this->isDeploymentProgress = false;
            }
        } catch (\Throwable $e) {
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

    public function stop()
    {
        StopService::run($this->service, false, $this->docker_cleanup);
        ServiceStatusChanged::dispatch();
    }

    public function restart()
    {
        $this->checkDeployments();
        if ($this->isDeploymentProgress) {
            $this->dispatch('error', 'There is a deployment in progress.');

            return;
        }
        StopService::run(service: $this->service, dockerCleanup: false);
        $this->service->parse();
        $this->dispatch('imagePulled');
        $activity = StartService::run($this->service);
        $this->dispatch('activityMonitor', $activity->id);
    }

    public function pullAndRestartEvent()
    {
        $this->checkDeployments();
        if ($this->isDeploymentProgress) {
            $this->dispatch('error', 'There is a deployment in progress.');

            return;
        }
        PullImage::run($this->service);
        StopService::run(service: $this->service, dockerCleanup: false);
        $this->service->parse();
        $this->dispatch('imagePulled');
        $activity = StartService::run($this->service);
        $this->dispatch('activityMonitor', $activity->id);
    }

    public function render()
    {
        return view('livewire.project.service.navbar', [
            'checkboxes' => [
                ['id' => 'docker_cleanup', 'label' => __('resource.docker_cleanup')],
            ],
        ]);
    }
}
