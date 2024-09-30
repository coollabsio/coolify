<?php

namespace App\Livewire\Project\New;

use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use Illuminate\Support\Str;
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
            $this->dockerComposeRaw = 'services:
            appsmith:
              build:
                context: .
                dockerfile_inline: |
                  FROM nginx
                  ARG GIT_COMMIT
                  ARG GIT_BRANCH
                  RUN echo "Hello World ${GIT_COMMIT} ${GIT_BRANCH}"
                args:
                  - GIT_COMMIT=cdc3b19
                  - GIT_BRANCH=${GIT_BRANCH}
              environment:
                - APPSMITH_MAIL_ENABLED=${APPSMITH_MAIL_ENABLED}
          ';
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

            $isValid = validateComposeFile($this->dockerComposeRaw, $server_id);
            if ($isValid !== 'OK') {
                return $this->dispatch('error', "Invalid docker-compose file.\n$isValid");
            }

            $project = Project::where('uuid', $this->parameters['project_uuid'])->first();
            $environment = $project->load(['environments'])->environments->where('name', $this->parameters['environment_name'])->first();

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
                'name' => 'service'.Str::random(10),
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
                    'service_id' => $service->id,
                ]);
            }
            $service->name = "service-$service->uuid";

            $service->parse(isNew: true);

            return redirect()->route('project.service.configuration', [
                'service_uuid' => $service->uuid,
                'environment_name' => $environment->name,
                'project_uuid' => $project->uuid,
            ]);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
