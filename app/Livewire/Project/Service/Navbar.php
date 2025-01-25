<?php

namespace App\Livewire\Project\Service;

use App\Actions\Service\StartService;
use App\Actions\Service\StopService;
use App\Actions\Shared\PullImage;
use App\Enums\ProcessStatus;
use App\Events\ServiceStatusChanged;
use App\Models\Service;
use Illuminate\Support\Facades\Auth;
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
        if (str($this->service->status)->contains('running') && is_null($this->service->config_hash)) {
            $this->service->isConfigurationChanged(true);
            $this->dispatch('configurationChanged');
        }
    }

    public function getListeners()
    {
        $userId = Auth::id();

        return [
            "echo-private:user.{$userId},ServiceStatusChanged" => 'serviceStarted',
            'envsUpdated' => '$refresh',
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
            $activity = Activity::where('properties->type_uuid', $this->service->uuid)->latest()->first();
            $status = data_get($activity, 'properties.status');
            if ($status === ProcessStatus::QUEUED->value || $status === ProcessStatus::IN_PROGRESS->value) {
                $this->isDeploymentProgress = true;
            } else {
                $this->isDeploymentProgress = false;
            }
        } catch (\Throwable) {
            $this->isDeploymentProgress = false;
        }

        return $this->isDeploymentProgress;
    }

    public function start()
    {
        $this->service->parse();
        $activity = StartService::run($this->service);
        $this->dispatch('activityMonitor', $activity->id);
    }

    public function forceDeploy()
    {
        try {
            $activities = Activity::where('properties->type_uuid', $this->service->uuid)->where('properties->status', ProcessStatus::IN_PROGRESS->value)->orWhere('properties->status', ProcessStatus::QUEUED->value)->get();
            foreach ($activities as $activity) {
                $activity->properties->status = ProcessStatus::ERROR->value;
                $activity->save();
            }
            $this->service->parse();
            $activity = StartService::run($this->service);
            $this->dispatch('activityMonitor', $activity->id);
        } catch (\Exception $e) {
            $this->dispatch('error', $e->getMessage());
        }
    }

    public function stop($cleanupContainers = false)
    {
        try {
            StopService::run($this->service, false, $this->docker_cleanup);
            ServiceStatusChanged::dispatch();
            if ($cleanupContainers) {
                $this->dispatch('success', 'Containers cleaned up.');
            } else {
                $this->dispatch('success', 'Service stopped.');
            }
        } catch (\Exception $e) {
            $this->dispatch('error', $e->getMessage());
        }
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
