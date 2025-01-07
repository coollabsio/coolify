<?php

namespace App\Livewire\Project;

use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use Exception;
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

    protected $messages = [
        'selectedServer' => 'Please select a server.',
        'selectedDestination' => 'Please select a server & destination.',
        'newName' => 'Please enter a name for the new project or environment.',
    ];

    public function mount($project_uuid)
    {
        $this->project_uuid = $project_uuid;
        $this->project = Project::query()->where('uuid', $project_uuid)->firstOrFail();
        $this->environment = $this->project->environments->where('uuid', $this->environment_uuid)->first();
        $this->project_id = $this->project->id;
        $this->servers = currentTeam()->servers;
        $this->newName = str($this->project->name.'-clone-'.new Cuid2)->slug();
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
                $foundProject = Project::query()->where('name', $this->newName)->first();
                if ($foundProject) {
                    throw new Exception('Project with the same name already exists.');
                }
                $project = Project::query()->create([
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
                    throw new Exception('Environment with the same name already exists.');
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
                $uuid = (string) new Cuid2;
                $newApplication = $application->replicate()->fill([
                    'uuid' => $uuid,
                    'fqdn' => generateFqdn($this->server, $uuid),
                    'status' => 'exited',
                    'environment_id' => $environment->id,
                    // This is not correct, but we need to set it to something
                    'destination_id' => $this->selectedDestination,
                ]);
                $newApplication->save();
                $environmentVaribles = $application->environment_variables()->get();
                foreach ($environmentVaribles as $environmentVarible) {
                    $newEnvironmentVariable = $environmentVarible->replicate()->fill([
                        'resourceable_id' => $newApplication->id,
                    ]);
                    $newEnvironmentVariable->save();
                }
                $persistentVolumes = $application->persistentStorages()->get();
                foreach ($persistentVolumes as $persistentVolume) {
                    $newPersistentVolume = $persistentVolume->replicate()->fill([
                        'name' => $newApplication->uuid.'-'.str($persistentVolume->name)->afterLast('-'),
                        'resource_id' => $newApplication->id,
                    ]);
                    $newPersistentVolume->save();
                }
            }
            foreach ($databases as $database) {
                $uuid = (string) new Cuid2;
                $newDatabase = $database->replicate()->fill([
                    'uuid' => $uuid,
                    'status' => 'exited',
                    'started_at' => null,
                    'environment_id' => $environment->id,
                    'destination_id' => $this->selectedDestination,
                ]);
                $newDatabase->save();
                $environmentVaribles = $database->environment_variables()->get();
                foreach ($environmentVaribles as $environmentVarible) {
                    $payload = [];
                    $payload['resourceable_id'] = $newDatabase->id;
                    $payload['resourceable_type'] = $newDatabase->getMorphClass();
                    $newEnvironmentVariable = $environmentVarible->replicate()->fill($payload);
                    $newEnvironmentVariable->save();
                }
            }
            foreach ($services as $service) {
                $uuid = (string) new Cuid2;
                $newService = $service->replicate()->fill([
                    'uuid' => $uuid,
                    'environment_id' => $environment->id,
                    'destination_id' => $this->selectedDestination,
                ]);
                $newService->save();
                foreach ($newService->applications() as $application) {
                    $application->update([
                        'status' => 'exited',
                    ]);
                }
                foreach ($newService->databases() as $database) {
                    $database->update([
                        'status' => 'exited',
                    ]);
                }
                $newService->parse();
            }

            return redirect()->route('project.resource.index', [
                'project_uuid' => $project->uuid,
                'environment_uuid' => $environment->uuid,
            ]);
        } catch (Exception $e) {
            return handleError($e, $this);
        }
    }
}
