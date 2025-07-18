<?php

namespace App\Livewire\Project\Service;

use App\Models\Service;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Configuration extends Component
{
    public $currentRoute;

    public $project;

    public $environment;

    public ?Service $service = null;

    public $applications;

    public $databases;

    public array $query;

    public array $parameters;

    public function getListeners()
    {
        $teamId = Auth::user()->currentTeam()->id;

        return [
            "echo-private:team.{$teamId},ServiceChecked" => 'serviceChecked',
        ];
    }

    public function render()
    {
        return view('livewire.project.service.configuration');
    }

    public function mount()
    {
        $this->parameters = get_route_parameters();
        $this->currentRoute = request()->route()->getName();
        $this->query = request()->query();
        $project = currentTeam()
            ->projects()
            ->select('id', 'uuid', 'team_id')
            ->where('uuid', request()->route('project_uuid'))
            ->firstOrFail();
        $environment = $project->environments()
            ->select('id', 'uuid', 'name', 'project_id')
            ->where('uuid', request()->route('environment_uuid'))
            ->firstOrFail();
        $this->service = $environment->services()->whereUuid(request()->route('service_uuid'))->firstOrFail();

        $this->project = $project;
        $this->environment = $environment;
        $this->applications = $this->service->applications->sort();
        $this->databases = $this->service->databases->sort();
    }

    public function refreshServices()
    {
        $this->service->refresh();
        $this->applications = $this->service->applications->sort();
        $this->databases = $this->service->databases->sort();
    }

    public function restartApplication($id)
    {
        try {
            $application = $this->service->applications->find($id);
            if ($application) {
                $application->restart();
                $this->dispatch('success', 'Service application restarted successfully.');
            }
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    public function restartDatabase($id)
    {
        try {
            $database = $this->service->databases->find($id);
            if ($database) {
                $database->restart();
                $this->dispatch('success', 'Service database restarted successfully.');
            }
        } catch (\Exception $e) {
            return handleError($e, $this);
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
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }
}
