<?php

namespace App\Livewire\Project\New;

use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\Service;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;

class DockerCompose extends Component
{
    use AuthorizesRequests;

    public string $dockerComposeRaw = '';

    public string $envFile = '';

    public array $parameters;

    public array $query;

    #[Locked]
    public ?string $createdServiceUuid = null;

    public function mount()
    {
        $this->parameters = get_route_parameters();
        $this->query = request()->query();
        if (isDev()) {
            $this->dockerComposeRaw = file_get_contents(base_path('templates/test-database-detection.yaml'));
        }
    }

    public function submit()
    {
        try {
            $this->authorize('create', Service::class);

            $this->validate([
                'dockerComposeRaw' => 'required',
            ]);
            $services = data_get(parseDockerComposeYaml($this->dockerComposeRaw), 'services');
            if (! is_array($services) || $services === [] || array_is_list($services)) {
                throw new \Exception('The Docker Compose file must define at least one service under "services".');
            }

            // Validate for command injection BEFORE saving to database
            validateDockerComposeForInjection($this->dockerComposeRaw);

            $project = Project::ownedByCurrentTeam()->where('uuid', $this->parameters['project_uuid'])->firstOrFail();
            $environment = $project->environments()->where('uuid', $this->parameters['environment_uuid'])->firstOrFail();

            $destination_uuid = $this->query['destination'] ?? null;
            $destination = find_resource_destination_for_current_team($destination_uuid);
            if (! $destination) {
                throw new \Exception('Destination not found.');
            }
            $destination_class = $destination->getMorphClass();

            if ($this->createdServiceUuid) {
                return $this->redirectToService($this->createdServiceUuid, $project, $environment);
            }

            $service = DB::transaction(function () use ($environment, $destination, $destination_class) {
                $service = new Service([
                    'docker_compose_raw' => $this->dockerComposeRaw,
                    'environment_id' => $environment->id,
                    'server_id' => $destination->server_id,
                    'destination_id' => $destination->id,
                    'destination_type' => $destination_class,
                ]);
                $service->save();

                $variables = parseEnvFormatToArray($this->envFile);
                foreach ($variables as $key => $data) {
                    // Extract value and comment from parsed data
                    // Handle both array format ['value' => ..., 'comment' => ...] and plain string values
                    $value = is_array($data) ? ($data['value'] ?? '') : $data;
                    $comment = is_array($data) ? ($data['comment'] ?? null) : null;

                    EnvironmentVariable::create([
                        'key' => $key,
                        'value' => $value,
                        'comment' => $comment,
                        'is_preview' => false,
                        'resourceable_id' => $service->id,
                        'resourceable_type' => $service->getMorphClass(),
                    ]);
                }
                $service->parse(isNew: true);

                // Apply service-specific application prerequisites
                applyServiceApplicationPrerequisites($service);

                return $service;
            });
            $this->createdServiceUuid = $service->uuid;

            return $this->redirectToService($service->uuid, $project, $environment);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    private function redirectToService(string $serviceUuid, Project $project, Environment $environment): mixed
    {
        return redirect()->route('project.service.configuration', [
            'service_uuid' => $serviceUuid,
            'environment_uuid' => $environment->uuid,
            'project_uuid' => $project->uuid,
        ]);
    }
}
