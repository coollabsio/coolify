<?php

namespace App\Http\Livewire\Project\New;

use App\Models\Application;
use App\Models\EnvironmentVariable;
use App\Models\GithubApp;
use App\Models\LocalPersistentVolume;
use App\Models\Project;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use Livewire\Component;
use Visus\Cuid2\Cuid2;
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
    documentation: https://docs.ghost.org/docs/config
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
    ports:
      - "2368"
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
        $destination_uuid = $this->query['destination'];
        $destination = StandaloneDocker::where('uuid', $destination_uuid)->first();
        if (!$destination) {
            $destination = SwarmDocker::where('uuid', $destination_uuid)->first();
        }
        if (!$destination) {
            throw new \Exception('Destination not found. What?!');
        }
        $destination_class = $destination->getMorphClass();

        $project = Project::where('uuid', $this->parameters['project_uuid'])->first();
        $environment = $project->load(['environments'])->environments->where('name', $this->parameters['environment_name'])->first();
        $application = Application::create([
            'name' => 'dockercompose-' . new Cuid2(7),
            'repository_project_id' => 0,
            'fqdn' => 'https://app.coolify.io',
            'git_repository' => "coollabsio/coolify",
            'git_branch' => 'main',
            'build_pack' => 'dockercompose',
            'ports_exposes' => '0',
            'dockercompose_raw' => $this->dockercompose,
            'environment_id' => $environment->id,
            'destination_id' => $destination->id,
            'destination_type' => $destination_class,
            'source_id' => 0,
            'source_type' => GithubApp::class
        ]);
        $fqdn = "http://{$application->uuid}.{$destination->server->ip}.sslip.io";
        if (isDev()) {
            $fqdn = "http://{$application->uuid}.127.0.0.1.sslip.io";
        }
        $application->update([
            'name' => 'dockercompose-' . $application->uuid,
            'fqdn' => $fqdn,
        ]);

        $details = generateServiceFromTemplate($this->dockercompose, $application);
        $envs = data_get($details, 'envs', []);
        if ($envs->count() > 0) {
            foreach ($envs as $env) {
                $key = Str::of($env)->before('=');
                $value = Str::of($env)->after('=');
                EnvironmentVariable::create([
                    'key' => $key,
                    'value' => $value,
                    'is_build_time' => false,
                    'application_id' => $application->id,
                    'is_preview' => false,
                ]);
            }
        }
        $volumes = data_get($details, 'volumes', []);
        if ($volumes->count() > 0) {
            foreach ($volumes as $volume => $mount_path) {
                LocalPersistentVolume::create([
                    'name' => $volume,
                    'mount_path' => $mount_path,
                    'resource_id' => $application->id,
                    'resource_type' => $application->getMorphClass(),
                    'is_readonly' => false
                ]);
            }
        }
        $dockercompose_coolified = data_get($details, 'dockercompose', '');
        $application->update([
            'dockercompose' => $dockercompose_coolified,
            'ports_exposes' => data_get($details, 'ports', 0)->implode(','),
        ]);


        redirect()->route('project.application.configuration', [
            'application_uuid' => $application->uuid,
            'environment_name' => $environment->name,
            'project_uuid' => $project->uuid,
        ]);
    }
}
