<?php

namespace App\Livewire\Project\Service;

use App\Actions\Docker\GetContainersStatus;
use App\Actions\Service\StartService;
use App\Actions\Service\StopService;
use App\Enums\ProcessStatus;
use App\Models\Service;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Spatie\Activitylog\Models\Activity;

class Heading extends Component
{
    use AuthorizesRequests;

    public Service $service;

    public array $parameters;

    public array $query;

    public $isDeploymentProgress = false;

    public $docker_cleanup = true;

    public $title = 'Configuration';

    public function mount()
    {
        $this->authorizeService('view');

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
        $this->authorizeService('view');

        if ($this->service->server->isFunctional()) {
            GetContainersStatus::dispatch($this->service->server);
        } else {
            $this->dispatch('error', 'Server is not functional.');
        }
    }

    public function manualCheckStatus()
    {
        $this->checkStatus();
    }

    public function serviceChecked()
    {
        $this->authorizeService('view');

        try {
            $this->service->applications->each(function ($application) {
                $application->refresh();
            });
            $this->service->databases->each(function ($database) {
                $database->refresh();
            });
            if (is_null($this->service->config_hash)) {
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
        $this->authorizeService('view');

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
        try {
            $this->authorizeService('deploy');
            $activity = StartService::run($this->service, pullLatestImages: true);
            $this->js("window.dispatchEvent(new CustomEvent('startservice'))");
            $this->dispatch('activityMonitor', $activity->id);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function forceDeploy()
    {
        try {
            $this->authorizeService('deploy');
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
            $this->js("window.dispatchEvent(new CustomEvent('startservice'))");
            $this->dispatch('activityMonitor', $activity->id);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function stop()
    {
        try {
            $this->authorizeService('stop');
            StopService::dispatch($this->service, false, $this->docker_cleanup);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function restart()
    {
        try {
            $this->authorizeService('deploy');
            $this->checkDeployments();
            if ($this->isDeploymentProgress) {
                $this->dispatch('error', 'There is a deployment in progress.');

                return;
            }
            $activity = StartService::run($this->service, stopBeforeStart: true);
            $this->js("window.dispatchEvent(new CustomEvent('startservice'))");
            $this->dispatch('activityMonitor', $activity->id);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function pullAndRestartEvent()
    {
        try {
            $this->authorizeService('deploy');
            $this->checkDeployments();
            if ($this->isDeploymentProgress) {
                $this->dispatch('error', 'There is a deployment in progress.');

                return;
            }
            $activity = StartService::run($this->service, pullLatestImages: true, stopBeforeStart: true);
            $this->js("window.dispatchEvent(new CustomEvent('startservice'))");
            $this->dispatch('activityMonitor', $activity->id);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    private function authorizeService(string $ability): void
    {
        $this->service = Service::ownedByCurrentTeam()
            ->whereKey($this->service->getKey())
            ->firstOrFail();

        $this->authorize($ability, $this->service);
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
