<?php

namespace App\Livewire\Project\Shared;

use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDocker;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\SwarmDocker;
use Livewire\Component;
use Throwable;
use Visus\Cuid2\Cuid2;

class ResourceOperations extends Component
{
    public $resource;

    public $projectUuid;

    public $environmentUuid;

    public $projects;

    public $servers;

    public function mount()
    {
        $parameters = get_route_parameters();
        $this->projectUuid = data_get($parameters, 'project_uuid');
        $this->environmentUuid = data_get($parameters, 'environment_uuid');
        $this->projects = Project::ownedByCurrentTeam()->get();
        $this->servers = currentTeam()->servers;
    }

    public function cloneTo($destination_id)
    {
        $new_destination = StandaloneDocker::query()->find($destination_id);
        if (! $new_destination) {
            $new_destination = SwarmDocker::query()->find($destination_id);
        }
        if (! $new_destination) {
            return $this->addError('destination_id', 'Destination not found.');
        }
        $uuid = (string) new Cuid2;
        $server = $new_destination->server;
        if ($this->resource->getMorphClass() === Application::class) {
            $name = 'clone-of-'.str($this->resource->name)->limit(20).'-'.$uuid;
            $new_resource = $this->resource->replicate()->fill([
                'uuid' => $uuid,
                'name' => $name,
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
                    'resourceable_id' => $new_resource->id,
                    'resourceable_type' => $new_resource->getMorphClass(),
                ]);
                $newEnvironmentVariable->save();
            }
            $persistentVolumes = $this->resource->persistentStorages()->get();
            foreach ($persistentVolumes as $persistentVolume) {
                $volumeName = str($persistentVolume->name)->replace($this->resource->uuid, $new_resource->uuid)->value();
                if ($volumeName === $persistentVolume->name) {
                    $volumeName = $new_resource->uuid.'-'.str($persistentVolume->name)->afterLast('-');
                }
                $newPersistentVolume = $persistentVolume->replicate()->fill([
                    'name' => $volumeName,
                    'resource_id' => $new_resource->id,
                ]);
                $newPersistentVolume->save();
            }
            $route = route('project.application.configuration', [
                'project_uuid' => $this->projectUuid,
                'environment_uuid' => $this->environmentUuid,
                'application_uuid' => $new_resource->uuid,
            ]).'#resource-operations';

            return redirect()->to($route);
        }
        if ($this->resource->getMorphClass() === StandalonePostgresql::class ||
        $this->resource->getMorphClass() === StandaloneMongodb::class ||
        $this->resource->getMorphClass() === StandaloneMysql::class ||
        $this->resource->getMorphClass() === StandaloneMariadb::class ||
        $this->resource->getMorphClass() === StandaloneRedis::class ||
        $this->resource->getMorphClass() === StandaloneKeydb::class ||
        $this->resource->getMorphClass() === StandaloneDragonfly::class ||
        $this->resource->getMorphClass() === StandaloneClickhouse::class) {
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
                'environment_uuid' => $this->environmentUuid,
                'database_uuid' => $new_resource->uuid,
            ]).'#resource-operations';

            return redirect()->to($route);
        }
        if ($this->resource->type() === 'service') {
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
                'environment_uuid' => $this->environmentUuid,
                'service_uuid' => $new_resource->uuid,
            ]).'#resource-operations';

            return redirect()->to($route);
        }

        return null;
    }

    public function moveTo($environment_id)
    {
        try {
            $new_environment = Environment::query()->findOrFail($environment_id);
            $this->resource->update([
                'environment_id' => $environment_id,
            ]);
            if ($this->resource->type() === 'application') {
                $route = route('project.application.configuration', [
                    'project_uuid' => $new_environment->project->uuid,
                    'environment_uuid' => $new_environment->uuid,
                    'application_uuid' => $this->resource->uuid,
                ]).'#resource-operations';

                return redirect()->to($route);
            }
            if (str($this->resource->type())->startsWith('standalone-')) {
                $route = route('project.database.configuration', [
                    'project_uuid' => $new_environment->project->uuid,
                    'environment_uuid' => $new_environment->uuid,
                    'database_uuid' => $this->resource->uuid,
                ]).'#resource-operations';

                return redirect()->to($route);
            }
            if ($this->resource->type() === 'service') {
                $route = route('project.service.configuration', [
                    'project_uuid' => $new_environment->project->uuid,
                    'environment_uuid' => $new_environment->uuid,
                    'service_uuid' => $this->resource->uuid,
                ]).'#resource-operations';

                return redirect()->to($route);
            }
        } catch (Throwable $e) {
            return handleError($e, $this);
        }

        return null;
    }

    public function render()
    {
        return view('livewire.project.shared.resource-operations');
    }
}
