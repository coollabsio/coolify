<?php

namespace App\Livewire\Project\Shared;

use App\Models\Environment;
use App\Models\Project;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class ResourceOperations extends Component
{
    public $resource;

    public $projectUuid;

    public $environmentName;

    public $projects;

    public $servers;

    public function mount()
    {
        $parameters = get_route_parameters();
        $this->projectUuid = data_get($parameters, 'project_uuid');
        $this->environmentName = data_get($parameters, 'environment_name');
        $this->projects = Project::ownedByCurrentTeam()->get();
        $this->servers = currentTeam()->servers;
    }

    public function cloneTo($destination_id)
    {
        $new_destination = StandaloneDocker::find($destination_id);
        if (! $new_destination) {
            $new_destination = SwarmDocker::find($destination_id);
        }
        if (! $new_destination) {
            return $this->addError('destination_id', 'Destination not found.');
        }
        $uuid = (string) new Cuid2;
        $server = $new_destination->server;
        if ($this->resource->getMorphClass() === 'App\Models\Application') {
            $new_resource = $this->resource->replicate()->fill([
                'uuid' => $uuid,
                'name' => $this->resource->name.'-clone-'.$uuid,
                'fqdn' => generateFqdn($server, $uuid),
                'status' => 'exited',
                'destination_id' => $new_destination->id,
            ]);
            $new_resource->save();
            if ($new_resource->destination->server->proxyType() !== 'NONE') {
                $customLabels = str(implode('|coolify|', generateLabelsApplication($new_resource)))->replace('|coolify|', "\n");
                $new_resource->custom_labels = base64_encode($customLabels);
                $new_resource->save();
            }
            $environmentVaribles = $this->resource->environment_variables()->get();
            foreach ($environmentVaribles as $environmentVarible) {
                $newEnvironmentVariable = $environmentVarible->replicate()->fill([
                    'application_id' => $new_resource->id,
                ]);
                $newEnvironmentVariable->save();
            }
            $persistentVolumes = $this->resource->persistentStorages()->get();
            foreach ($persistentVolumes as $volume) {
                $newPersistentVolume = $volume->replicate()->fill([
                    'name' => $new_resource->uuid.'-'.str($volume->name)->afterLast('-'),
                    'resource_id' => $new_resource->id,
                ]);
                $newPersistentVolume->save();
            }
            $route = route('project.application.configuration', [
                'project_uuid' => $this->projectUuid,
                'environment_name' => $this->environmentName,
                'application_uuid' => $new_resource->uuid,
            ]).'#resource-operations';

            return redirect()->to($route);
        } elseif (
            $this->resource->getMorphClass() === 'App\Models\StandalonePostgresql' ||
            $this->resource->getMorphClass() === 'App\Models\StandaloneMongodb' ||
            $this->resource->getMorphClass() === 'App\Models\StandaloneMysql' ||
            $this->resource->getMorphClass() === 'App\Models\StandaloneMariadb' ||
            $this->resource->getMorphClass() === 'App\Models\StandaloneRedis' ||
            $this->resource->getMorphClass() === 'App\Models\StandaloneKeydb' ||
            $this->resource->getMorphClass() === 'App\Models\StandaloneDragonfly' ||
            $this->resource->getMorphClass() === 'App\Models\StandaloneClickhouse'
        ) {
            $uuid = (string) new Cuid2;
            $new_resource = $this->resource->replicate()->fill([
                'uuid' => $uuid,
                'name' => $this->resource->name.'-clone-'.$uuid,
                'status' => 'exited',
                'started_at' => null,
                'destination_id' => $new_destination->id,
            ]);
            $new_resource->save();
            $environmentVaribles = $this->resource->environment_variables()->get();
            foreach ($environmentVaribles as $environmentVarible) {
                $payload = [];
                if ($this->resource->type() === 'standalone-postgresql') {
                    $payload['standalone_postgresql_id'] = $new_resource->id;
                } elseif ($this->resource->type() === 'standalone-redis') {
                    $payload['standalone_redis_id'] = $new_resource->id;
                } elseif ($this->resource->type() === 'standalone-mongodb') {
                    $payload['standalone_mongodb_id'] = $new_resource->id;
                } elseif ($this->resource->type() === 'standalone-mysql') {
                    $payload['standalone_mysql_id'] = $new_resource->id;
                } elseif ($this->resource->type() === 'standalone-mariadb') {
                    $payload['standalone_mariadb_id'] = $new_resource->id;
                }
                $newEnvironmentVariable = $environmentVarible->replicate()->fill($payload);
                $newEnvironmentVariable->save();
            }
            $route = route('project.database.configuration', [
                'project_uuid' => $this->projectUuid,
                'environment_name' => $this->environmentName,
                'database_uuid' => $new_resource->uuid,
            ]).'#resource-operations';

            return redirect()->to($route);
        } elseif ($this->resource->type() === 'service') {
            $uuid = (string) new Cuid2;
            $new_resource = $this->resource->replicate()->fill([
                'uuid' => $uuid,
                'name' => $this->resource->name.'-clone-'.$uuid,
                'destination_id' => $new_destination->id,
            ]);
            $new_resource->save();
            foreach ($new_resource->applications() as $application) {
                $application->update([
                    'status' => 'exited',
                ]);
            }
            foreach ($new_resource->databases() as $database) {
                $database->update([
                    'status' => 'exited',
                ]);
            }
            $new_resource->parse();
            $route = route('project.service.configuration', [
                'project_uuid' => $this->projectUuid,
                'environment_name' => $this->environmentName,
                'service_uuid' => $new_resource->uuid,
            ]).'#resource-operations';

            return redirect()->to($route);
        }

    }

    public function moveTo($environment_id)
    {
        try {
            $new_environment = Environment::findOrFail($environment_id);
            $this->resource->update([
                'environment_id' => $environment_id,
            ]);
            if ($this->resource->type() === 'application') {
                $route = route('project.application.configuration', [
                    'project_uuid' => $new_environment->project->uuid,
                    'environment_name' => $new_environment->name,
                    'application_uuid' => $this->resource->uuid,
                ]).'#resource-operations';

                return redirect()->to($route);
            } elseif (str($this->resource->type())->startsWith('standalone-')) {
                $route = route('project.database.configuration', [
                    'project_uuid' => $new_environment->project->uuid,
                    'environment_name' => $new_environment->name,
                    'database_uuid' => $this->resource->uuid,
                ]).'#resource-operations';

                return redirect()->to($route);
            } elseif ($this->resource->type() === 'service') {
                $route = route('project.service.configuration', [
                    'project_uuid' => $new_environment->project->uuid,
                    'environment_name' => $new_environment->name,
                    'service_uuid' => $this->resource->uuid,
                ]).'#resource-operations';

                return redirect()->to($route);
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.project.shared.resource-operations');
    }
}
