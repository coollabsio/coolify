<?php

namespace App\Livewire\Project\New;

use App\Models\Application;
use App\Models\Project;
use App\Support\StaticSiteArchive;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class StaticArchive extends Component
{
    use AuthorizesRequests;

    public array $parameters;

    public array $query;

    public function mount(): void
    {
        $this->parameters = get_route_parameters();
        $this->query = request()->query();
    }

    /**
     * Creates the site from an archive that the dropzone staged for the current team, and deploys it.
     */
    public function createFromUpload(string $uploadId)
    {
        try {
            $destination = find_resource_destination_for_current_team($this->query['destination'] ?? null);
            if (! $destination) {
                throw new \Exception('Destination not found.');
            }
            $project = Project::ownedByCurrentTeam()->where('uuid', $this->parameters['project_uuid'])->firstOrFail();
            $environment = $project->environments()->where('uuid', $this->parameters['environment_uuid'])->firstOrFail();
            $this->authorize('create', [Application::class, $environment]);
            $teamId = (int) $project->team_id;
            if (! StaticSiteArchive::hasStagedUpload($teamId, $uploadId)) {
                throw new \Exception('The upload was not found or has expired. Upload the archive again.');
            }

            $application = StaticSiteArchive::createApplication($environment, $destination);
            $this->authorize('deploy', $application);
            $result = StaticSiteArchive::publish($application, $teamId, $uploadId);

            $parameters = [
                'project_uuid' => $project->uuid,
                'environment_uuid' => $environment->uuid,
                'application_uuid' => $application->uuid,
            ];
            if (filled($result['deployment_uuid'])) {
                return redirect()->route('project.application.deployment.show', [...$parameters, 'deployment_uuid' => $result['deployment_uuid']]);
            }

            return redirect()->route('project.application.configuration', $parameters);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.project.new.static-archive');
    }
}
