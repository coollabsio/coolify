<?php

namespace App\Livewire\Railway\Concerns;

use App\Models\Environment;
use App\Models\Project;
use Illuminate\Support\Collection;

/**
 * Loads the project/environment context and the switcher collections used by the
 * Railway in-project chrome (top bar + rail). Shared across the Railway pages.
 */
trait LoadsProjectContext
{
    public Project $project;

    public Environment $environment;

    public Collection $allProjects;

    public Collection $allEnvironments;

    protected function loadProjectContext(string $projectUuid, string $environmentUuid): void
    {
        $project = currentTeam()
            ->projects()
            ->where('uuid', $projectUuid)
            ->firstOrFail();

        $environment = $project->environments()
            ->where('uuid', $environmentUuid)
            ->firstOrFail();

        $this->project = $project;
        $this->environment = $environment;
        $this->allProjects = Project::ownedByCurrentTeamCached();
        $this->allEnvironments = $project->environments()->select('id', 'uuid', 'name', 'project_id')->get();
    }
}
