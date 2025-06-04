<?php

namespace App\Livewire\Project\Service;

use App\Actions\Docker\GetContainersStatus;
use App\Actions\Service\StartService;
use App\Actions\Service\StopService;
use App\Enums\ProcessStatus;
use App\Events\ServiceStatusChanged;
use App\Models\Service;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Spatie\Activitylog\Models\Activity;

class Heading extends Component
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
        $teamId = Auth::user()->currentTeam()->id;

        return [
            "echo-private:team.{$teamId},ServiceStatusChanged" => 'checkStatus',
            "echo-private:team.{$teamId},ServiceChecked" => 'serviceChecked',
            'refresh' => '$refresh',
            'envsUpdated' => '$refresh',
        ];
    }

    public function checkStatus()
    {
        if ($this->service->server->isFunctional()) {
            GetContainersStatus::dispatch($this->service->server);
        } else {
            $this->dispatch('error', 'Server is not functional.');
        }
    }

    public function serviceChecked()
    {
        try {
            $this->service->applications->each(function ($application) {
                $application->refresh();
            });
            $this->service->databases->each(function ($database) {
                $database->refresh();
            });
            if (is_null($this->service->config_hash) || $this->service->isConfigurationChanged()) {
                $this->service->isConfigurationChanged(true);
            }
            $this->dispatch('configurationChanged');
        } catch (\Exception $e) {
            return handleError($e, $this);
        } finally {
            $this->dispatch('refresh')->self();
        }

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
        $activity = StartService::run($this->service, pullLatestImages: true);
        $this->dispatch('activityMonitor', $activity->id, ServiceStatusChanged::class);
    }

    public function forceDeploy()
    {
        try {
            $activities = Activity::where('properties->type_uuid', $this->service->uuid)
                ->where(function ($q) {
                    $q->where('properties->status', ProcessStatus::IN_PROGRESS->value)
                        ->orWhere('properties->status', ProcessStatus::QUEUED->value);
                })->get();
            foreach ($activities as $activity) {
                $activity->properties->status = ProcessStatus::ERROR->value;
                $activity->save();
            }
            $activity = StartService::run($this->service, pullLatestImages: true, stopBeforeStart: true);
            $this->dispatch('activityMonitor', $activity->id, ServiceStatusChanged::class);
        } catch (\Exception $e) {
            $this->dispatch('error', $e->getMessage());
        }
    }

    public function stop()
    {
        try {
            StopService::dispatch($this->service, false, $this->docker_cleanup);
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
        $activity = StartService::run($this->service, stopBeforeStart: true);
        $this->dispatch('activityMonitor', $activity->id, ServiceStatusChanged::class);
    }

    public function pullAndRestartEvent()
    {
        $this->checkDeployments();
        if ($this->isDeploymentProgress) {
            $this->dispatch('error', 'There is a deployment in progress.');

            return;
        }
        $activity = StartService::run($this->service, pullLatestImages: true, stopBeforeStart: true);
        $this->dispatch('activityMonitor', $activity->id, ServiceStatusChanged::class);
    }

    public function render()
    {
        return view('livewire.project.service.heading', [
            'checkboxes' => [
                ['id' => 'docker_cleanup', 'label' => __('resource.docker_cleanup')],
            ],
        ]);
    }
}
