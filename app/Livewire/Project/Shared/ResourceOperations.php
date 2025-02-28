<?php

namespace App\Livewire\Project\Shared;

use App\Actions\Application\StopApplication;
use App\Actions\Database\StartDatabase;
use App\Actions\Database\StopDatabase;
use App\Actions\Service\StartService;
use App\Actions\Service\StopService;
use App\Jobs\VolumeCloneJob;
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

    public $environmentUuid;

    public $projects;

    public $servers;

    public bool $cloneVolumeData = false;

    public function mount()
    {
        $parameters = get_route_parameters();
        $this->projectUuid = data_get($parameters, 'project_uuid');
        $this->environmentUuid = data_get($parameters, 'environment_uuid');
        $this->projects = Project::ownedByCurrentTeam()->get();
        $this->servers = currentTeam()->servers;
    }

    public function toggleVolumeCloning(bool $value)
    {
        $this->cloneVolumeData = $value;
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

        if ($this->resource->getMorphClass() === \App\Models\Application::class) {
            $name = 'clone-of-'.str($this->resource->name)->limit(20).'-'.$uuid;
            $applicationSettings = $this->resource->settings;
            $url = $this->resource->fqdn;

            if ($server->proxyType() !== 'NONE' && $applicationSettings->is_container_label_readonly_enabled === true) {
                $url = generateFqdn($server, $uuid);
            }

            $new_resource = $this->resource->replicate([
                'id',
                'created_at',
                'updated_at',
                'additional_servers_count',
                'additional_networks_count',
            ])->fill([
                'uuid' => $uuid,
                'name' => $name,
                'fqdn' => $url,
                'status' => 'exited',
                'destination_id' => $new_destination->id,
            ]);
            $new_resource->save();

            if ($new_resource->destination->server->proxyType() !== 'NONE' && $applicationSettings->is_container_label_readonly_enabled === true) {
                $customLabels = str(implode('|coolify|', generateLabelsApplication($new_resource)))->replace('|coolify|', "\n");
                $new_resource->custom_labels = base64_encode($customLabels);
                $new_resource->save();
            }

            $new_resource->settings()->delete();
            if ($applicationSettings) {
                $newApplicationSettings = $applicationSettings->replicate([
                    'id',
                    'created_at',
                    'updated_at',
                ])->fill([
                    'application_id' => $new_resource->id,
                ]);
                $newApplicationSettings->save();
            }

            $tags = $this->resource->tags;
            foreach ($tags as $tag) {
                $new_resource->tags()->attach($tag->id);
            }

            $scheduledTasks = $this->resource->scheduled_tasks()->get();
            foreach ($scheduledTasks as $task) {
                $newTask = $task->replicate([
                    'id',
                    'created_at',
                    'updated_at',
                ])->fill([
                    'uuid' => (string) new Cuid2,
                    'application_id' => $new_resource->id,
                    'team_id' => currentTeam()->id,
                ]);
                $newTask->save();
            }

            $applicationPreviews = $this->resource->previews()->get();
            foreach ($applicationPreviews as $preview) {
                $newPreview = $preview->replicate([
                    'id',
                    'created_at',
                    'updated_at',
                ])->fill([
                    'application_id' => $new_resource->id,
                    'status' => 'exited',
                ]);
                $newPreview->save();
            }

            $persistentVolumes = $this->resource->persistentStorages()->get();
            foreach ($persistentVolumes as $volume) {
                $newName = '';
                if (str_starts_with($volume->name, $this->resource->uuid)) {
                    $newName = str($volume->name)->replace($this->resource->uuid, $new_resource->uuid);
                } else {
                    $newName = $new_resource->uuid.'-'.str($volume->name)->afterLast('-');
                }

                $newPersistentVolume = $volume->replicate([
                    'id',
                    'created_at',
                    'updated_at',
                ])->fill([
                    'name' => $newName,
                    'resource_id' => $new_resource->id,
                ]);
                $newPersistentVolume->save();

                if ($this->cloneVolumeData) {
                    try {
                        StopApplication::dispatch($this->resource, false, false);
                        $sourceVolume = $volume->name;
                        $targetVolume = $newPersistentVolume->name;
                        $sourceServer = $this->resource->destination->server;
                        $targetServer = $new_resource->destination->server;

                        VolumeCloneJob::dispatch($sourceVolume, $targetVolume, $sourceServer, $targetServer, $newPersistentVolume);

                        queue_application_deployment(
                            deployment_uuid: (string) new Cuid2,
                            application: $this->resource,
                            server: $sourceServer,
                            destination: $this->resource->destination,
                            no_questions_asked: true
                        );
                    } catch (\Exception $e) {
                        \Log::error('Failed to copy volume data for '.$volume->name.': '.$e->getMessage());
                    }
                }
            }

            $fileStorages = $this->resource->fileStorages()->get();
            foreach ($fileStorages as $storage) {
                $newStorage = $storage->replicate([
                    'id',
                    'created_at',
                    'updated_at',
                ])->fill([
                    'resource_id' => $new_resource->id,
                ]);
                $newStorage->save();
            }

            $environmentVaribles = $this->resource->environment_variables()->get();
            foreach ($environmentVaribles as $environmentVarible) {
                $newEnvironmentVariable = $environmentVarible->replicate([
                    'id',
                    'created_at',
                    'updated_at',
                ])->fill([
                    'resourceable_id' => $new_resource->id,
                    'resourceable_type' => $new_resource->getMorphClass(),
                ]);
                $newEnvironmentVariable->save();
            }

            $route = route('project.application.configuration', [
                'project_uuid' => $this->projectUuid,
                'environment_uuid' => $this->environmentUuid,
                'application_uuid' => $new_resource->uuid,
            ]).'#resource-operations';

            return redirect()->to($route);
        } elseif (
            $this->resource->getMorphClass() === \App\Models\StandalonePostgresql::class ||
            $this->resource->getMorphClass() === \App\Models\StandaloneMongodb::class ||
            $this->resource->getMorphClass() === \App\Models\StandaloneMysql::class ||
            $this->resource->getMorphClass() === \App\Models\StandaloneMariadb::class ||
            $this->resource->getMorphClass() === \App\Models\StandaloneRedis::class ||
            $this->resource->getMorphClass() === \App\Models\StandaloneKeydb::class ||
            $this->resource->getMorphClass() === \App\Models\StandaloneDragonfly::class ||
            $this->resource->getMorphClass() === \App\Models\StandaloneClickhouse::class
        ) {
            $uuid = (string) new Cuid2;
            $new_resource = $this->resource->replicate([
                'id',
                'created_at',
                'updated_at',
            ])->fill([
                'uuid' => $uuid,
                'name' => $this->resource->name.'-clone-'.$uuid,
                'status' => 'exited',
                'started_at' => null,
                'destination_id' => $new_destination->id,
            ]);
            $new_resource->save();

            $tags = $this->resource->tags;
            foreach ($tags as $tag) {
                $new_resource->tags()->attach($tag->id);
            }

            $new_resource->persistentStorages()->delete();
            $persistentVolumes = $this->resource->persistentStorages()->get();
            foreach ($persistentVolumes as $volume) {
                $originalName = $volume->name;
                $newName = '';

                if (str_starts_with($originalName, 'postgres-data-')) {
                    $newName = 'postgres-data-'.$new_resource->uuid;
                } elseif (str_starts_with($originalName, 'mysql-data-')) {
                    $newName = 'mysql-data-'.$new_resource->uuid;
                } elseif (str_starts_with($originalName, 'redis-data-')) {
                    $newName = 'redis-data-'.$new_resource->uuid;
                } elseif (str_starts_with($originalName, 'clickhouse-data-')) {
                    $newName = 'clickhouse-data-'.$new_resource->uuid;
                } elseif (str_starts_with($originalName, 'mariadb-data-')) {
                    $newName = 'mariadb-data-'.$new_resource->uuid;
                } elseif (str_starts_with($originalName, 'mongodb-data-')) {
                    $newName = 'mongodb-data-'.$new_resource->uuid;
                } elseif (str_starts_with($originalName, 'keydb-data-')) {
                    $newName = 'keydb-data-'.$new_resource->uuid;
                } elseif (str_starts_with($originalName, 'dragonfly-data-')) {
                    $newName = 'dragonfly-data-'.$new_resource->uuid;
                } else {
                    if (str_starts_with($volume->name, $this->resource->uuid)) {
                        $newName = str($volume->name)->replace($this->resource->uuid, $new_resource->uuid);
                    } else {
                        $newName = $new_resource->uuid.'-'.$volume->name;
                    }
                }

                $newPersistentVolume = $volume->replicate([
                    'id',
                    'created_at',
                    'updated_at',
                ])->fill([
                    'name' => $newName,
                    'resource_id' => $new_resource->id,
                ]);
                $newPersistentVolume->save();

                if ($this->cloneVolumeData) {
                    try {
                        StopDatabase::dispatch($this->resource);
                        $sourceVolume = $volume->name;
                        $targetVolume = $newPersistentVolume->name;
                        $sourceServer = $this->resource->destination->server;
                        $targetServer = $new_resource->destination->server;

                        VolumeCloneJob::dispatch($sourceVolume, $targetVolume, $sourceServer, $targetServer, $newPersistentVolume);

                        StartDatabase::dispatch($this->resource);
                    } catch (\Exception $e) {
                        \Log::error('Failed to copy volume data for '.$volume->name.': '.$e->getMessage());
                    }
                }
            }

            $fileStorages = $this->resource->fileStorages()->get();
            foreach ($fileStorages as $storage) {
                $newStorage = $storage->replicate([
                    'id',
                    'created_at',
                    'updated_at',
                ])->fill([
                    'resource_id' => $new_resource->id,
                ]);
                $newStorage->save();
            }

            $scheduledBackups = $this->resource->scheduledBackups()->get();
            foreach ($scheduledBackups as $backup) {
                $uuid = (string) new Cuid2;
                $newBackup = $backup->replicate([
                    'id',
                    'created_at',
                    'updated_at',
                ])->fill([
                    'uuid' => $uuid,
                    'database_id' => $new_resource->id,
                    'database_type' => $new_resource->getMorphClass(),
                    'team_id' => currentTeam()->id,
                ]);
                $newBackup->save();
            }

            $environmentVaribles = $this->resource->environment_variables()->get();
            foreach ($environmentVaribles as $environmentVarible) {
                $payload = [
                    'resourceable_id' => $new_resource->id,
                    'resourceable_type' => $new_resource->getMorphClass(),
                ];
                $newEnvironmentVariable = $environmentVarible->replicate([
                    'id',
                    'created_at',
                    'updated_at',
                ])->fill($payload);
                $newEnvironmentVariable->save();
            }

            $route = route('project.database.configuration', [
                'project_uuid' => $this->projectUuid,
                'environment_uuid' => $this->environmentUuid,
                'database_uuid' => $new_resource->uuid,
            ]).'#resource-operations';

            return redirect()->to($route);
        } elseif ($this->resource->type() === 'service') {
            $uuid = (string) new Cuid2;
            $new_resource = $this->resource->replicate([
                'id',
                'created_at',
                'updated_at',
            ])->fill([
                'uuid' => $uuid,
                'name' => $this->resource->name.'-clone-'.$uuid,
                'destination_id' => $new_destination->id,
                'destination_type' => $new_destination->getMorphClass(),
                'server_id' => $new_destination->server_id, // server_id is probably not needed anymore because of the new polymorphic relationships (here it is needed for clone to a different server to work - but maybe we can drop the column)
            ]);

            $new_resource->save();

            $tags = $this->resource->tags;
            foreach ($tags as $tag) {
                $new_resource->tags()->attach($tag->id);
            }

            $scheduledTasks = $this->resource->scheduled_tasks()->get();
            foreach ($scheduledTasks as $task) {
                $newTask = $task->replicate([
                    'id',
                    'created_at',
                    'updated_at',
                ])->fill([
                    'uuid' => (string) new Cuid2,
                    'service_id' => $new_resource->id,
                    'team_id' => currentTeam()->id,
                ]);
                $newTask->save();
            }

            $environmentVariables = $this->resource->environment_variables()->get();
            foreach ($environmentVariables as $environmentVariable) {
                $newEnvironmentVariable = $environmentVariable->replicate([
                    'id',
                    'created_at',
                    'updated_at',
                ])->fill([
                    'resourceable_id' => $new_resource->id,
                    'resourceable_type' => $new_resource->getMorphClass(),
                ]);
                $newEnvironmentVariable->save();
            }

            foreach ($new_resource->applications() as $application) {
                $application->update([
                    'status' => 'exited',
                ]);

                $persistentVolumes = $application->persistentStorages()->get();
                foreach ($persistentVolumes as $volume) {
                    $newName = '';
                    if (str_starts_with($volume->name, $volume->resource->uuid)) {
                        $newName = str($volume->name)->replace($volume->resource->uuid, $application->uuid);
                    } else {
                        $newName = $application->uuid.'-'.str($volume->name)->afterLast('-');
                    }

                    $newPersistentVolume = $volume->replicate([
                        'id',
                        'created_at',
                        'updated_at',
                    ])->fill([
                        'name' => $newName,
                        'resource_id' => $application->id,
                    ]);
                    $newPersistentVolume->save();

                    if ($this->cloneVolumeData) {
                        try {
                            StopService::dispatch($application, false, false);
                            $sourceVolume = $volume->name;
                            $targetVolume = $newPersistentVolume->name;
                            $sourceServer = $application->service->destination->server;
                            $targetServer = $new_resource->destination->server;

                            VolumeCloneJob::dispatch($sourceVolume, $targetVolume, $sourceServer, $targetServer, $newPersistentVolume);

                            StartService::dispatch($application);
                        } catch (\Exception $e) {
                            \Log::error('Failed to copy volume data for '.$volume->name.': '.$e->getMessage());
                        }
                    }
                }
            }

            foreach ($new_resource->databases() as $database) {
                $database->update([
                    'status' => 'exited',
                ]);

                $persistentVolumes = $database->persistentStorages()->get();
                foreach ($persistentVolumes as $volume) {
                    $newName = '';
                    if (str_starts_with($volume->name, $volume->resource->uuid)) {
                        $newName = str($volume->name)->replace($volume->resource->uuid, $database->uuid);
                    } else {
                        $newName = $database->uuid.'-'.str($volume->name)->afterLast('-');
                    }

                    $newPersistentVolume = $volume->replicate([
                        'id',
                        'created_at',
                        'updated_at',
                    ])->fill([
                        'name' => $newName,
                        'resource_id' => $database->id,
                    ]);
                    $newPersistentVolume->save();

                    if ($this->cloneVolumeData) {
                        try {
                            StopService::dispatch($database->service, false, false);
                            $sourceVolume = $volume->name;
                            $targetVolume = $newPersistentVolume->name;
                            $sourceServer = $database->service->destination->server;
                            $targetServer = $new_resource->destination->server;

                            VolumeCloneJob::dispatch($sourceVolume, $targetVolume, $sourceServer, $targetServer, $newPersistentVolume);

                            StartService::dispatch($database->service);
                        } catch (\Exception $e) {
                            \Log::error('Failed to copy volume data for '.$volume->name.': '.$e->getMessage());
                        }
                    }
                }
            }

            $new_resource->parse();

            $route = route('project.service.configuration', [
                'project_uuid' => $this->projectUuid,
                'environment_uuid' => $this->environmentUuid,
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
                    'environment_uuid' => $new_environment->uuid,
                    'application_uuid' => $this->resource->uuid,
                ]).'#resource-operations';

                return redirect()->to($route);
            } elseif (str($this->resource->type())->startsWith('standalone-')) {
                $route = route('project.database.configuration', [
                    'project_uuid' => $new_environment->project->uuid,
                    'environment_uuid' => $new_environment->uuid,
                    'database_uuid' => $this->resource->uuid,
                ]).'#resource-operations';

                return redirect()->to($route);
            } elseif ($this->resource->type() === 'service') {
                $route = route('project.service.configuration', [
                    'project_uuid' => $new_environment->project->uuid,
                    'environment_uuid' => $new_environment->uuid,
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
