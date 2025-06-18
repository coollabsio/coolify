<?php

namespace App\Livewire\Project\New;

use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use Livewire\Component;
use Symfony\Component\Yaml\Yaml;

class DockerCompose extends Component
{
    public string $dockerComposeRaw = '';

    public string $envFile = '';

    public array $parameters;

    public array $query;

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
        $server_id = $this->query['server_id'];
        try {
            $this->validate([
                'dockerComposeRaw' => 'required',
            ]);
            $this->dockerComposeRaw = Yaml::dump(Yaml::parse($this->dockerComposeRaw), 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
            $project = Project::where('uuid', $this->parameters['project_uuid'])->first();
            $environment = $project->load(['environments'])->environments->where('uuid', $this->parameters['environment_uuid'])->first();

            $destination_uuid = $this->query['destination'];
            $destination = StandaloneDocker::where('uuid', $destination_uuid)->first();
            if (! $destination) {
                $destination = SwarmDocker::where('uuid', $destination_uuid)->first();
            }
            if (! $destination) {
                throw new \Exception('Destination not found. What?!');
            }
            $destination_class = $destination->getMorphClass();

            $service = Service::create([
                'docker_compose_raw' => $this->dockerComposeRaw,
                'environment_id' => $environment->id,
                'server_id' => (int) $server_id,
                'destination_id' => $destination->id,
                'destination_type' => $destination_class,
            ]);

            $variables = parseEnvFormatToArray($this->envFile);
            foreach ($variables as $key => $variable) {
                EnvironmentVariable::create([
                    'key' => $key,
                    'value' => $variable,
                    'is_build_time' => false,
                    'is_preview' => false,
                    'resourceable_id' => $service->id,
                    'resourceable_type' => $service->getMorphClass(),
                ]);
            }
            $service->parse(isNew: true);

            return redirect()->route('project.service.configuration', [
                'service_uuid' => $service->uuid,
                'environment_uuid' => $environment->uuid,
                'project_uuid' => $project->uuid,
            ]);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
