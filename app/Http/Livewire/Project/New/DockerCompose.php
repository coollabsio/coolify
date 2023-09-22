<?php

namespace App\Http\Livewire\Project\New;

use App\Models\Project;
use App\Models\Service;
use Livewire\Component;
use Illuminate\Support\Str;

class DockerCompose extends Component
{
    public string $dockercompose = '';
    public array $parameters;
    public array $query;
    public function mount()
    {
        $this->parameters = get_route_parameters();
        $this->query = request()->query();
        if (isDev()) {
            $this->dockercompose = 'services:
  ghost:
    documentation: https://ghost.org/docs/config
    image: ghost:5
    volumes:
      - ghost-content-data:/var/lib/ghost/content
    environment:
      - url=$SERVICE_FQDN_GHOST
      - database__client=mysql
      - database__connection__host=mysql
      - database__connection__user=$SERVICE_USER_MYSQL
      - database__connection__password=$SERVICE_PASSWORD_MYSQL
      - database__connection__database=${MYSQL_DATABASE-ghost}
    depends_on:
      - mysql
  mysql:
    documentation: https://hub.docker.com/_/mysql
    image: mysql:8.0
    volumes:
      - ghost-mysql-data:/var/lib/mysql
    environment:
      - MYSQL_USER=${SERVICE_USER_MYSQL}
      - MYSQL_PASSWORD=${SERVICE_PASSWORD_MYSQL}
      - MYSQL_DATABASE=${MYSQL_DATABASE}
      - MYSQL_ROOT_PASSWORD=${SERVICE_PASSWORD_MYSQL_ROOT}
';
        }
    }
    public function submit()
    {
        $this->validate([
            'dockercompose' => 'required'
        ]);
        $server_id = $this->query['server_id'];

        $project = Project::where('uuid', $this->parameters['project_uuid'])->first();
        $environment = $project->load(['environments'])->environments->where('name', $this->parameters['environment_name'])->first();

        $service = Service::create([
            'name' => 'service' . Str::random(10),
            'docker_compose_raw' => $this->dockercompose,
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
    }
}
