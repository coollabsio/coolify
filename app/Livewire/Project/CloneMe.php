<?php

namespace App\Livewire\Project;

use App\Actions\Database\StartDatabase;
use App\Actions\Database\StopDatabase;
use App\Actions\Service\StartService;
use App\Actions\Service\StopService;
use App\Jobs\VolumeCloneJob;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Support\ValidationPatterns;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

use function redirect;
use function str;
use function view;
use function currentTeam;
use function clone_application;
use function handleError;

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

    protected function messages(): array
    {
        return array_merge([
            'selectedServer' => 'Please select a server.',
            'selectedDestination' => 'Please select a server & destination.',
            'newName.required' => 'Please enter a name for the new project or environment.',
        ], ValidationPatterns::nameMessages());
    }

    public function mount($project_uuid)
    {
        $this->project_uuid = $project_uuid;
        $this->project = Project::where('uuid', $project_uuid)->firstOrFail();
        $this->environment = $this->project->environments->where('uuid', $this->environment_uuid)->first();
        $this->project_id = $this->project->id;
        $this->servers = currentTeam()
            ->servers()
            ->get()
            ->reject(fn($server) => $server->isBuildServer());
        $this->newName = str($this->project->name . '-clone-' . (string) new Cuid2)->slug();
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
                'newName' => ValidationPatterns::nameRules(),
            ]);
            if ($type === 'project') {
                $foundProject = Project::where('name', $this->newName)->first();
                if ($foundProject) {
                    throw new \Exception('Project with the same name already exists.');
                }
                $project = Project::create([
                    'name' => $this->newName,
                    'team_id' => currentTeam()->id,
                    'description' => $this->project->description . ' (clone)',
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
                $selectedDestination = $this->servers->flatMap(fn($server) => $server->destinations())->where('id', $this->selectedDestination)->first();
                clone_application($application, $selectedDestination, [
                    'environment_id' => $environment->id,
                ], $this->cloneVolumeData);
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
                        $newName = 'postgres-data-' . $newDatabase->uuid;
                    } elseif (str_starts_with($originalName, 'mysql-data-')) {
                        $newName = 'mysql-data-' . $newDatabase->uuid;
                    } elseif (str_starts_with($originalName, 'redis-data-')) {
                        $newName = 'redis-data-' . $newDatabase->uuid;
                    } elseif (str_starts_with($originalName, 'clickhouse-data-')) {
                        $newName = 'clickhouse-data-' . $newDatabase->uuid;
                    } elseif (str_starts_with($originalName, 'mariadb-data-')) {
                        $newName = 'mariadb-data-' . $newDatabase->uuid;
                    } elseif (str_starts_with($originalName, 'mongodb-data-')) {
                        $newName = 'mongodb-data-' . $newDatabase->uuid;
                    } elseif (str_starts_with($originalName, 'keydb-data-')) {
                        $newName = 'keydb-data-' . $newDatabase->uuid;
                    } elseif (str_starts_with($originalName, 'dragonfly-data-')) {
                        $newName = 'dragonfly-data-' . $newDatabase->uuid;
                    } else {
                        if (str_starts_with($volume->name, $database->uuid)) {
                            $newName = str($volume->name)->replace($database->uuid, $newDatabase->uuid);
                        } else {
                            $newName = $newDatabase->uuid . '-' . $volume->name;
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
                            \Log::error('Failed to copy volume data for ' . $volume->name . ': ' . $e->getMessage());
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

                $newService->parse();

                foreach ($newService->applications as $newServiceApp) {
                    $newServiceApp->update([
                        'status' => 'exited',
                    ]);
                    $oldServiceApp = $service->applications->where('name', $newServiceApp->name)->first();
                    if (!$oldServiceApp) {
                        continue;
                    }

                    $persistentVolumes = $oldServiceApp->persistentStorages()->get();
                    foreach ($persistentVolumes as $volume) {
                        $newName = '';
                        if (str_starts_with($volume->name, $oldServiceApp->uuid)) {
                            $newName = str($volume->name)->replace($oldServiceApp->uuid, $newServiceApp->uuid);
                        } else {
                            $newName = $newServiceApp->uuid . '-' . $volume->name;
                        }

                        $newPersistentVolume = $volume->replicate([
                            'id',
                            'created_at',
                            'updated_at',
                        ])->fill([
                                    'name' => $newName,
                                    'resource_id' => $newServiceApp->id,
                                ]);
                        $newPersistentVolume->save();

                        if ($this->cloneVolumeData) {
                            try {
                                StopService::dispatch($oldServiceApp);
                                $sourceVolume = $volume->name;
                                $targetVolume = $newPersistentVolume->name;
                                $sourceServer = $oldServiceApp->service->destination->server;
                                $targetServer = $newService->destination->server;

                                VolumeCloneJob::dispatch($sourceVolume, $targetVolume, $sourceServer, $targetServer, $newPersistentVolume);

                                StartService::dispatch($oldServiceApp);
                            } catch (\Exception $e) {
                                \Log::error('Failed to copy volume data for ' . $volume->name . ': ' . $e->getMessage());
                            }
                        }
                    }

                    $fileStorages = $oldServiceApp->fileStorages()->get();
                    foreach ($fileStorages as $storage) {
                        $newStorage = $storage->replicate([
                            'id',
                            'created_at',
                            'updated_at',
                        ])->fill([
                                    'resource_id' => $newServiceApp->id,
                                ]);
                        $newStorage->save();
                    }
                }

                foreach ($newService->databases as $newServiceDb) {
                    $newServiceDb->update([
                        'status' => 'exited',
                    ]);
                    $oldServiceDb = $service->databases->where('name', $newServiceDb->name)->first();
                    if (!$oldServiceDb) {
                        continue;
                    }

                    $persistentVolumes = $oldServiceDb->persistentStorages()->get();
                    foreach ($persistentVolumes as $volume) {
                        $newName = '';
                        if (str_starts_with($volume->name, $oldServiceDb->uuid)) {
                            $newName = str($volume->name)->replace($oldServiceDb->uuid, $newServiceDb->uuid);
                        } else {
                            $newName = $newServiceDb->uuid . '-' . $volume->name;
                        }

                        $newPersistentVolume = $volume->replicate([
                            'id',
                            'created_at',
                            'updated_at',
                        ])->fill([
                                    'name' => $newName,
                                    'resource_id' => $newServiceDb->id,
                                ]);
                        $newPersistentVolume->save();

                        if ($this->cloneVolumeData) {
                            try {
                                StopService::dispatch($oldServiceDb->service);
                                $sourceVolume = $volume->name;
                                $targetVolume = $newPersistentVolume->name;
                                $sourceServer = $oldServiceDb->service->destination->server;
                                $targetServer = $newService->destination->server;

                                VolumeCloneJob::dispatch($sourceVolume, $targetVolume, $sourceServer, $targetServer, $newPersistentVolume);

                                StartService::dispatch($oldServiceDb->service);
                            } catch (\Exception $e) {
                                \Log::error('Failed to copy volume data for ' . $volume->name . ': ' . $e->getMessage());
                            }
                        }
                    }

                    $fileStorages = $oldServiceDb->fileStorages()->get();
                    foreach ($fileStorages as $storage) {
                        $newStorage = $storage->replicate([
                            'id',
                            'created_at',
                            'updated_at',
                        ])->fill([
                                    'resource_id' => $newServiceDb->id,
                                ]);
                        $newStorage->save();
                    }

                    $scheduledBackups = $oldServiceDb->scheduledBackups()->get();
                    foreach ($scheduledBackups as $backup) {
                        $uuid = (string) new Cuid2;
                        $newBackup = $backup->replicate([
                            'id',
                            'created_at',
                            'updated_at',
                        ])->fill([
                                    'uuid' => $uuid,
                                    'database_id' => $newServiceDb->id,
                                    'database_type' => $newServiceDb->getMorphClass(),
                                    'team_id' => currentTeam()->id,
                                ]);
                        $newBackup->save();
                    }
                }
            }

        } catch (\Exception $e) {
            handleError($e, $this);

            return;
        } finally {
            if (!isset($e)) {
                return redirect()->route('project.resource.index', [
                    'project_uuid' => $project->uuid,
                    'environment_uuid' => $environment->uuid,
                ]);
            }
        }
    }
}
