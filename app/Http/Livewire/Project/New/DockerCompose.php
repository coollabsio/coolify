<?php

namespace App\Http\Livewire\Project\New;

use App\Models\Application;
use App\Models\EnvironmentVariable;
use App\Models\GithubApp;
use App\Models\LocalPersistentVolume;
use App\Models\Project;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use Livewire\Component;
use Visus\Cuid2\Cuid2;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

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
        $service = new Service();
        $service->uuid = (string) new Cuid2(7);
        $service->name = 'service-' . new Cuid2(7);
        $service->docker_compose_raw = $this->dockercompose;
        $service->environment_id = $environment->id;
        $service->destination_id = $destination->id;
        $service->destination_type = $destination_class;
        $service->save();
        $service->parse(saveIt: true);

        return redirect()->route('project.service', [
            'service_uuid' => $service->uuid,
            'environment_name' => $environment->name,
            'project_uuid' => $project->uuid,
        ]);
        // $compose = data_get($parsedService, 'docker_compose');
        // $service->docker_compose = $compose;
        // $shouldDefine = data_get($parsedService, 'should_define', collect([]));
        // if ($shouldDefine->count() > 0) {
        //     $envs = data_get($shouldDefine, 'envs', []);
        //     foreach($envs as $env) {
        //         ray($env);
        //         $variableName = Str::of($env)->before('=');
        //         $variableValue = Str::of($env)->after('=');
        //         ray($variableName, $variableValue);
        //     }
        // }
        // foreach ($services as $serviceName => $serviceDetails) {
        //     if (data_get($serviceDetails,'is_database')) {
        //         $serviceDatabase = new ServiceDatabase();
        //         $serviceDatabase->name = $serviceName . '-' . $service->uuid;
        //         $serviceDatabase->service_id = $service->id;
        //         $serviceDatabase->save();
        //     } else {
        //         $serviceApplication = new ServiceApplication();
        //         $serviceApplication->name = $serviceName . '-' . $service->uuid;
        //         $serviceApplication->fqdn =
        //         $serviceApplication->service_id = $service->id;
        //         $serviceApplication->save();
        //     }
        // }

        // ray($details);
        // $envs = data_get($details, 'envs', []);
        // if ($envs->count() > 0) {
        //     foreach ($envs as $env) {
        //         $key = Str::of($env)->before('=');
        //         $value = Str::of($env)->after('=');
        //         EnvironmentVariable::create([
        //             'key' => $key,
        //             'value' => $value,
        //             'is_build_time' => false,
        //             'service_id' => $service->id,
        //             'is_preview' => false,
        //         ]);
        //     }
        // }
        // $volumes = data_get($details, 'volumes', []);
        // if ($volumes->count() > 0) {
        //     foreach ($volumes as $volume => $mount_path) {
        //         LocalPersistentVolume::create([
        //             'name' => $volume,
        //             'mount_path' => $mount_path,
        //             'resource_id' => $service->id,
        //             'resource_type' => $service->getMorphClass(),
        //             'is_readonly' => false
        //         ]);
        //     }
        // }
        // $dockercompose_coolified = data_get($details, 'dockercompose', '');
        // $service->update([
        //     'docker_compose' => $dockercompose_coolified,
        // ]);



    }
}
