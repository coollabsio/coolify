<?php

namespace App\Livewire\Project;

use App\Actions\Application\StopApplication;
use App\Actions\Database\StartDatabase;
use App\Actions\Database\StopDatabase;
use App\Actions\Service\StartService;
use App\Actions\Service\StopService;
use App\Jobs\VolumeCloneJob;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class CloneMe extends Component
{
    public string $project_uuid;

    public string $environment_uuid;

    public int $project_id;

    public Project $project;

    public $environments;

    public $servers;

    public ?Environment $environment = null;

    public ?int $selectedServer = null;

    public ?int $selectedDestination = null;

    public ?Server $server = null;

    public $resources = [];

    public string $newName = '';

    public bool $cloneVolumeData = false;

    protected $messages = [
        'selectedServer' => 'Please select a server.',
        'selectedDestination' => 'Please select a server & destination.',
        'newName' => 'Please enter a name for the new project or environment.',
    ];

    public function mount($project_uuid)
    {
        $this->project_uuid = $project_uuid;
        $this->project = Project::where('uuid', $project_uuid)->firstOrFail();
        $this->environment = $this->project->environments->where('uuid', $this->environment_uuid)->first();
        $this->project_id = $this->project->id;
        $this->servers = currentTeam()->servers;
        $this->newName = str($this->project->name.'-clone-'.(string) new Cuid2)->slug();
    }

    public function toggleVolumeCloning(bool $value)
    {
        $this->cloneVolumeData = $value;
    }

    public function render()
    {
        return view('livewire.project.clone-me');
    }

    public function selectServer($server_id, $destination_id)
    {
        if ($server_id == $this->selectedServer && $destination_id == $this->selectedDestination) {
            $this->selectedServer = null;
            $this->selectedDestination = null;
            $this->server = null;

            return;
        }
        $this->selectedServer = $server_id;
        $this->selectedDestination = $destination_id;
        $this->server = $this->servers->where('id', $server_id)->first();
    }

    public function clone(string $type)
    {
        try {
            $this->validate([
                'selectedDestination' => 'required',
                'newName' => 'required',
            ]);
            if ($type === 'project') {
                $foundProject = Project::where('name', $this->newName)->first();
                if ($foundProject) {
                    throw new \Exception('Project with the same name already exists.');
                }
                $project = Project::create([
                    'name' => $this->newName,
                    'team_id' => currentTeam()->id,
                    'description' => $this->project->description.' (clone)',
                ]);
                if ($this->environment->name !== 'production') {
                    $project->environments()->create([
                        'name' => $this->environment->name,
                        'uuid' => (string) new Cuid2,
                    ]);
                }
                $environment = $project->environments->where('name', $this->environment->name)->first();
            } else {
                $foundEnv = $this->project->environments()->where('name', $this->newName)->first();
                if ($foundEnv) {
                    throw new \Exception('Environment with the same name already exists.');
                }
                $project = $this->project;
                $environment = $this->project->environments()->create([
                    'name' => $this->newName,
                    'uuid' => (string) new Cuid2,
                ]);
            }
            $applications = $this->environment->applications;
            $databases = $this->environment->databases();
            $services = $this->environment->services;
            foreach ($applications as $application) {
                $applicationSettings = $application->settings;

                $uuid = (string) new Cuid2;
                $url = $application->fqdn;
                if ($this->server->proxyType() !== 'NONE' && $applicationSettings->is_container_label_readonly_enabled === true) {
                    $url = generateFqdn($this->server, $uuid);
                }

                $newApplication = $application->replicate([
                    'id',
                    'created_at',
                    'updated_at',
                    'additional_servers_count',
                    'additional_networks_count',
                ])->fill([
                    'uuid' => $uuid,
                    'fqdn' => $url,
                    'status' => 'exited',
                    'environment_id' => $environment->id,
                    'destination_id' => $this->selectedDestination,
                ]);
                $newApplication->save();

                if ($newApplication->destination->server->proxyType() !== 'NONE' && $applicationSettings->is_container_label_readonly_enabled === true) {
                    $customLabels = str(implode('|coolify|', generateLabelsApplication($newApplication)))->replace('|coolify|', "\n");
                    $newApplication->custom_labels = base64_encode($customLabels);
                    $newApplication->save();
                }

                $newApplication->settings()->delete();
                if ($applicationSettings) {
                    $newApplicationSettings = $applicationSettings->replicate([
                        'id',
                        'created_at',
                        'updated_at',
                    ])->fill([
                        'application_id' => $newApplication->id,
                    ]);
                    $newApplicationSettings->save();
                }

                $tags = $application->tags;
                foreach ($tags as $tag) {
                    $newApplication->tags()->attach($tag->id);
                }

                $scheduledTasks = $application->scheduled_tasks()->get();
                foreach ($scheduledTasks as $task) {
                    $newTask = $task->replicate([
                        'id',
                        'created_at',
                        'updated_at',
                    ])->fill([
                        'uuid' => (string) new Cuid2,
                        'application_id' => $newApplication->id,
                        'team_id' => currentTeam()->id,
                    ]);
                    $newTask->save();
                }

                $applicationPreviews = $application->previews()->get();
                foreach ($applicationPreviews as $preview) {
                    $newPreview = $preview->replicate([
                        'id',
                        'created_at',
                        'updated_at',
                    ])->fill([
                        'application_id' => $newApplication->id,
                        'status' => 'exited',
                    ]);
                    $newPreview->save();
                }

                $persistentVolumes = $application->persistentStorages()->get();
                foreach ($persistentVolumes as $volume) {
                    $newName = '';
                    if (str_starts_with($volume->name, $application->uuid)) {
                        $newName = str($volume->name)->replace($application->uuid, $newApplication->uuid);
                    } else {
                        $newName = $newApplication->uuid.'-'.$volume->name;
                    }

                    $newPersistentVolume = $volume->replicate([
                        'id',
                        'created_at',
                        'updated_at',
                    ])->fill([
                        'name' => $newName,
                        'resource_id' => $newApplication->id,
                    ]);
                    $newPersistentVolume->save();

                    if ($this->cloneVolumeData) {
                        try {
                            StopApplication::dispatch($application, false, false);
                            $sourceVolume = $volume->name;
                            $targetVolume = $newPersistentVolume->name;
                            $sourceServer = $application->destination->server;
                            $targetServer = $newApplication->destination->server;

                            VolumeCloneJob::dispatch($sourceVolume, $targetVolume, $sourceServer, $targetServer, $newPersistentVolume);

                            queue_application_deployment(
                                deployment_uuid: (string) new Cuid2,
                                application: $application,
                                server: $sourceServer,
                                destination: $application->destination,
                                no_questions_asked: true
                            );
                        } catch (\Exception $e) {
                            \Log::error('Failed to copy volume data for '.$volume->name.': '.$e->getMessage());
                        }
                    }
                }

                $fileStorages = $application->fileStorages()->get();
                foreach ($fileStorages as $storage) {
                    $newStorage = $storage->replicate([
                        'id',
                        'created_at',
                        'updated_at',
                    ])->fill([
                        'resource_id' => $newApplication->id,
                    ]);
                    $newStorage->save();
                }

                $environmentVaribles = $application->environment_variables()->get();
                foreach ($environmentVaribles as $environmentVarible) {
                    $newEnvironmentVariable = $environmentVarible->replicate([
                        'id',
                        'created_at',
                        'updated_at',
                    ])->fill([
                        'resourceable_id' => $newApplication->id,
                    ]);
                    $newEnvironmentVariable->save();
                }
            }

            foreach ($databases as $database) {
                $uuid = (string) new Cuid2;
                $newDatabase = $database->replicate([
                    'id',
                    'created_at',
                    'updated_at',
                ])->fill([
                    'uuid' => $uuid,
                    'status' => 'exited',
                    'started_at' => null,
                    'environment_id' => $environment->id,
                    'destination_id' => $this->selectedDestination,
                ]);
                $newDatabase->save();

                $tags = $database->tags;
                foreach ($tags as $tag) {
                    $newDatabase->tags()->attach($tag->id);
                }

                $newDatabase->persistentStorages()->delete();
                $persistentVolumes = $database->persistentStorages()->get();
                foreach ($persistentVolumes as $volume) {
                    $originalName = $volume->name;
                    $newName = '';

                    if (str_starts_with($originalName, 'postgres-data-')) {
                        $newName = 'postgres-data-'.$newDatabase->uuid;
                    } elseif (str_starts_with($originalName, 'mysql-data-')) {
                        $newName = 'mysql-data-'.$newDatabase->uuid;
                    } elseif (str_starts_with($originalName, 'redis-data-')) {
                        $newName = 'redis-data-'.$newDatabase->uuid;
                    } elseif (str_starts_with($originalName, 'clickhouse-data-')) {
                        $newName = 'clickhouse-data-'.$newDatabase->uuid;
                    } elseif (str_starts_with($originalName, 'mariadb-data-')) {
                        $newName = 'mariadb-data-'.$newDatabase->uuid;
                    } elseif (str_starts_with($originalName, 'mongodb-data-')) {
                        $newName = 'mongodb-data-'.$newDatabase->uuid;
                    } elseif (str_starts_with($originalName, 'keydb-data-')) {
                        $newName = 'keydb-data-'.$newDatabase->uuid;
                    } elseif (str_starts_with($originalName, 'dragonfly-data-')) {
                        $newName = 'dragonfly-data-'.$newDatabase->uuid;
                    } else {
                        if (str_starts_with($volume->name, $database->uuid)) {
                            $newName = str($volume->name)->replace($database->uuid, $newDatabase->uuid);
                        } else {
                            $newName = $newDatabase->uuid.'-'.$volume->name;
                        }
                    }

                    $newPersistentVolume = $volume->replicate([
                        'id',
                        'created_at',
                        'updated_at',
                    ])->fill([
                        'name' => $newName,
                        'resource_id' => $newDatabase->id,
                    ]);
                    $newPersistentVolume->save();

                    if ($this->cloneVolumeData) {
                        try {
                            StopDatabase::dispatch($database);
                            $sourceVolume = $volume->name;
                            $targetVolume = $newPersistentVolume->name;
                            $sourceServer = $database->destination->server;
                            $targetServer = $newDatabase->destination->server;

                            VolumeCloneJob::dispatch($sourceVolume, $targetVolume, $sourceServer, $targetServer, $newPersistentVolume);

                            StartDatabase::dispatch($database);
                        } catch (\Exception $e) {
                            \Log::error('Failed to copy volume data for '.$volume->name.': '.$e->getMessage());
                        }
                    }
                }

                $fileStorages = $database->fileStorages()->get();
                foreach ($fileStorages as $storage) {
                    $newStorage = $storage->replicate([
                        'id',
                        'created_at',
                        'updated_at',
                    ])->fill([
                        'resource_id' => $newDatabase->id,
                    ]);
                    $newStorage->save();
                }

                $scheduledBackups = $database->scheduledBackups()->get();
                foreach ($scheduledBackups as $backup) {
                    $uuid = (string) new Cuid2;
                    $newBackup = $backup->replicate([
                        'id',
                        'created_at',
                        'updated_at',
                    ])->fill([
                        'uuid' => $uuid,
                        'database_id' => $newDatabase->id,
                        'database_type' => $newDatabase->getMorphClass(),
                        'team_id' => currentTeam()->id,
                    ]);
                    $newBackup->save();
                }

                $environmentVaribles = $database->environment_variables()->get();
                foreach ($environmentVaribles as $environmentVarible) {
                    $payload = [];
                    $payload['resourceable_id'] = $newDatabase->id;
                    $payload['resourceable_type'] = $newDatabase->getMorphClass();
                    $newEnvironmentVariable = $environmentVarible->replicate([
                        'id',
                        'created_at',
                        'updated_at',
                    ])->fill($payload);
                    $newEnvironmentVariable->save();
                }
            }

            foreach ($services as $service) {
                $uuid = (string) new Cuid2;
                $newService = $service->replicate([
                    'id',
                    'created_at',
                    'updated_at',
                ])->fill([
                    'uuid' => $uuid,
                    'environment_id' => $environment->id,
                    'destination_id' => $this->selectedDestination,
                ]);
                $newService->save();

                $tags = $service->tags;
                foreach ($tags as $tag) {
                    $newService->tags()->attach($tag->id);
                }

                $scheduledTasks = $service->scheduled_tasks()->get();
                foreach ($scheduledTasks as $task) {
                    $newTask = $task->replicate([
                        'id',
                        'created_at',
                        'updated_at',
                    ])->fill([
                        'uuid' => (string) new Cuid2,
                        'service_id' => $newService->id,
                        'team_id' => currentTeam()->id,
                    ]);
                    $newTask->save();
                }

                $environmentVariables = $service->environment_variables()->get();
                foreach ($environmentVariables as $environmentVariable) {
                    $newEnvironmentVariable = $environmentVariable->replicate([
                        'id',
                        'created_at',
                        'updated_at',
                    ])->fill([
                        'resourceable_id' => $newService->id,
                        'resourceable_type' => $newService->getMorphClass(),
                    ]);
                    $newEnvironmentVariable->save();
                }

                foreach ($newService->applications() as $application) {
                    $application->update([
                        'status' => 'exited',
                    ]);

                    $persistentVolumes = $application->persistentStorages()->get();
                    foreach ($persistentVolumes as $volume) {
                        $newName = '';
                        if (str_starts_with($volume->name, $application->uuid)) {
                            $newName = str($volume->name)->replace($application->uuid, $application->uuid);
                        } else {
                            $newName = $application->uuid.'-'.$volume->name;
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
                                $targetServer = $newService->destination->server;

                                VolumeCloneJob::dispatch($sourceVolume, $targetVolume, $sourceServer, $targetServer, $newPersistentVolume);

                                StartService::dispatch($application);
                            } catch (\Exception $e) {
                                \Log::error('Failed to copy volume data for '.$volume->name.': '.$e->getMessage());
                            }
                        }
                    }

                    $fileStorages = $application->fileStorages()->get();
                    foreach ($fileStorages as $storage) {
                        $newStorage = $storage->replicate([
                            'id',
                            'created_at',
                            'updated_at',
                        ])->fill([
                            'resource_id' => $application->id,
                        ]);
                        $newStorage->save();
                    }
                }

                foreach ($newService->databases() as $database) {
                    $database->update([
                        'status' => 'exited',
                    ]);

                    $persistentVolumes = $database->persistentStorages()->get();
                    foreach ($persistentVolumes as $volume) {
                        $newName = '';
                        if (str_starts_with($volume->name, $database->uuid)) {
                            $newName = str($volume->name)->replace($database->uuid, $database->uuid);
                        } else {
                            $newName = $database->uuid.'-'.$volume->name;
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
                                $targetServer = $newService->destination->server;

                                VolumeCloneJob::dispatch($sourceVolume, $targetVolume, $sourceServer, $targetServer, $newPersistentVolume);

                                StartService::dispatch($database->service);
                            } catch (\Exception $e) {
                                \Log::error('Failed to copy volume data for '.$volume->name.': '.$e->getMessage());
                            }
                        }
                    }

                    $fileStorages = $database->fileStorages()->get();
                    foreach ($fileStorages as $storage) {
                        $newStorage = $storage->replicate([
                            'id',
                            'created_at',
                            'updated_at',
                        ])->fill([
                            'resource_id' => $database->id,
                        ]);
                        $newStorage->save();
                    }

                    $scheduledBackups = $database->scheduledBackups()->get();
                    foreach ($scheduledBackups as $backup) {
                        $uuid = (string) new Cuid2;
                        $newBackup = $backup->replicate([
                            'id',
                            'created_at',
                            'updated_at',
                        ])->fill([
                            'uuid' => $uuid,
                            'database_id' => $database->id,
                            'database_type' => $database->getMorphClass(),
                            'team_id' => currentTeam()->id,
                        ]);
                        $newBackup->save();
                    }
                }

                $newService->parse();
            }

        } catch (\Exception $e) {
            handleError($e, $this);

            return;
        } finally {
            if (! isset($e)) {
                return redirect()->route('project.resource.index', [
                    'project_uuid' => $project->uuid,
                    'environment_uuid' => $environment->uuid,
                ]);
            }
        }
    }
}
