<?php

namespace App\Livewire\Project\New;

use App\Models\Application;
use App\Models\Environment;
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
     * Returns a presigned URL that uploads the archive straight to the upload storage of the instance.
     *
     * @return array{upload_id: string, url: string, headers: array<string, string>, expires_at: string}|null
     */
    public function prepareS3Upload(): ?array
    {
        try {
            $environment = $this->environment();

            return StaticSiteArchive::prepareS3Upload((int) $environment->project->team_id);
        } catch (\Throwable $e) {
            handleError($e, $this);

            return null;
        }
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
            $environment = $this->environment();
            $project = $environment->project;
            $teamId = (int) $project->team_id;
            StaticSiteArchive::verifyStagedUpload($teamId, $uploadId);

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

    /**
     * The environment of the new site. The user must be able to create applications in it.
     */
    private function environment(): Environment
    {
        $project = Project::ownedByCurrentTeam()->where('uuid', $this->parameters['project_uuid'])->firstOrFail();
        $environment = $project->environments()->where('uuid', $this->parameters['environment_uuid'])->firstOrFail();
        $this->authorize('create', [Application::class, $environment]);

        return $environment;
    }

    public function render()
    {
        return view('livewire.project.new.static-archive');
    }
}
