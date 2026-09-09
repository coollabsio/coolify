<?php

namespace App\Livewire\Project\Application;

use App\Models\Application;
use Livewire\Component;

class Configuration extends Component
{
    public $currentRoute;

    public Application $application;

    public $project;

    public $environment;

    public $servers;

    protected $listeners = [
        'buildPackUpdated' => '$refresh',
        'refresh' => '$refresh',
    ];

    public function mount()
    {
        $this->syncCurrentRoute();

        $project = currentTeam()
            ->projects()
            ->select('id', 'uuid', 'name', 'team_id')
            ->where('uuid', request()->route('project_uuid'))
            ->firstOrFail();
        $environment = $project->environments()
            ->select('id', 'uuid', 'name', 'project_id')
            ->where('uuid', request()->route('environment_uuid'))
            ->firstOrFail();
        $application = $environment->applications()
            ->with(['destination.server', 'environment.project'])
            ->where('uuid', request()->route('application_uuid'))
            ->firstOrFail();

        // Parent page already resolved these; keep them on the model for nested components.
        $application->setRelation('environment', $environment);
        $environment->setRelation('project', $project);

        $this->project = $project;
        $this->environment = $environment;
        $this->application = $application;

        if ($this->application->build_pack === 'dockercompose' && $this->currentRoute === 'project.application.healthcheck') {
            return redirect()->route('project.application.configuration', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'application_uuid' => $application->uuid]);
        }
    }

    /**
     * Keep sidebar active state in sync on full-page navigations.
     * Ignore Livewire update requests so poll/refresh does not clear it.
     */
    protected function syncCurrentRoute(): void
    {
        $routeName = request()->route()?->getName();

        if (is_string($routeName) && str_starts_with($routeName, 'project.application.')) {
            $this->currentRoute = $routeName;
        }
    }

    public function render()
    {
        $this->syncCurrentRoute();

        return view('livewire.project.application.configuration');
    }
}
