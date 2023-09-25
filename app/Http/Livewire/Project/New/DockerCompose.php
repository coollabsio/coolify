<?php

namespace App\Http\Livewire\Project\New;

use App\Models\Project;
use App\Models\Service;
use Livewire\Component;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

class DockerCompose extends Component
{
    public string $dockerComposeRaw = '';
    public array $parameters;
    public array $query;
    public function mount()
    {

        $this->parameters = get_route_parameters();
        $this->query = request()->query();
        if (isDev()) {
            $this->dockerComposeRaw = 'services:
  plausible_events_db:
    image: clickhouse/clickhouse-server:23.3.7.5-alpine
    restart: always
    volumes:
        - event-data:/var/lib/clickhouse
        - ./clickhouse/clickhouse-config.xml:/etc/clickhouse-server/config.d/logging.xml:ro
        - ./clickhouse/clickhouse-user-config.xml:/etc/clickhouse-server/users.d/logging.xml:ro
    ulimits:
      nofile:
        soft: 262144
        hard: 262144
';
        }
    }
    public function submit()
    {
        try {
            $this->validate([
                'dockerComposeRaw' => 'required'
            ]);
            $this->dockerComposeRaw = Yaml::dump(Yaml::parse($this->dockerComposeRaw), 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
            $server_id = $this->query['server_id'];

            $project = Project::where('uuid', $this->parameters['project_uuid'])->first();
            $environment = $project->load(['environments'])->environments->where('name', $this->parameters['environment_name'])->first();

            $service = Service::create([
                'name' => 'service' . Str::random(10),
                'docker_compose_raw' => $this->dockerComposeRaw,
                'environment_id' => $environment->id,
                'server_id' => (int) $server_id,
            ]);
            $service->name = "service-$service->uuid";

            $service->parse(isNew: true);

            return redirect()->route('project.service', [
                'service_uuid' => $service->uuid,
                'environment_name' => $environment->name,
                'project_uuid' => $project->uuid,
            ]);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }

    }
}
