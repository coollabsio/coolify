<?php

namespace App\Livewire\Project\Shared;

use App\Models\Application;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class UploadConfig extends Component
{
    public $config;

    public $project_uuid;

    public $environment_uuid;

    public $destination_uuid;

    public function mount()
    {
        if (isDev()) {
            $this->setExampleConfig('dockerfile');
        }
        $this->project_uuid = request()->route('project_uuid');
        $this->environment_uuid = request()->route('environment_uuid');
    }

    public function setExampleConfig(string $buildPack)
    {
        $this->destination_uuid = Server::first()->destinations()->first()->uuid;

        switch ($buildPack) {
            case 'dockerfile':
                $this->config = '{
    "name": "Example Application",
    "description": "This is an example application configuration",
    "coolify": {
        "project_uuid": "'.$this->project_uuid.'",
        "environment_uuid": "'.$this->environment_uuid.'",
        "destination_uuid": "'.$this->destination_uuid.'"
    },
    "build": {
        "build_pack": "dockerfile",
        "dockerfile": {
            "content": "FROM nginx:latest"
        }
    },
    "network": {
        "ports": {
            "expose": "80"
        }
    }
}';
            case 'dockerfile-without-coolify':
                $this->config = '{
    "name": "Example Application",
    "description": "This is an example application configuration",
    "build": {
        "build_pack": "dockerfile",
        "dockerfile": {
            "content": "FROM nginx:latest"
        }
    },
    "network": {
        "ports": {
            "expose": "80"
        }
    }
}';
                break;
            case 'git-dockerfile':
                $this->config = '{
    "name": "Example Application",
    "description": "This is an example application configuration",
    "coolify": {
        "project_uuid": "'.$this->project_uuid.'",
        "environment_uuid": "'.$this->environment_uuid.'",
        "destination_uuid": "'.$this->destination_uuid.'"
    },
    "source": {
        "git_repository": "https://github.com/coollabsio/coolify-examples",
        "git_branch": "main"
    },
    "build": {
        "build_pack": "dockerfile",
        "base_directory": "/dockerfile"
    }
}';
                break;
            case 'git-dockerfile-persistent-storage-scheduled-jobs':
                $this->config = '{
    "name": "Example Application",
    "description": "This is an example application configuration",
    "coolify": {
        "project_uuid": "'.$this->project_uuid.'",
        "environment_uuid": "'.$this->environment_uuid.'",
        "destination_uuid": "'.$this->destination_uuid.'"
    },
    "source": {
        "git_repository": "https://github.com/coollabsio/coolify-examples",
        "git_branch": "main"
    },
    "build": {
        "build_pack": "dockerfile",
        "base_directory": "/dockerfile"
    },
    "network": {
        "domains": {
            "fqdn": "http://dockerfile.127.0.0.1.sslip.io"
        },
        "ports": {
            "expose": "80"
        }
    },
    "environment_variables": {
        "production": [
        {
            "key": "isProduction",
            "value": "true"
        }
        ],
        "preview": [
        {
            "key": "isProduction",
            "value": "false"
        }
        ]
    },
    "persistent_storages": [
        {
            "mount_path": "/test"
        }
    ],
    "scheduled_tasks": [
        {
            "enabled": true,
            "name": "Scheduled Task 1",
            "command": "ls",
            "frequency": "daily"
        }
    ],
    "tags": [
        "tag1",
        "tag2"
    ]
}';
                break;
        }
    }

    public function uploadConfig()
    {
        try {
            $config = json_decode($this->config, true);
            $project_uuid = data_get($config, 'coolify.project_uuid', $this->project_uuid);
            $environment_uuid = data_get($config, 'coolify.environment_uuid', $this->environment_uuid);
            $destination_uuid = data_get($config, 'coolify.destination_uuid', $this->destination_uuid);

            if (blank($destination_uuid)) {
                if (Server::ownedByCurrentTeam()->count() == 1) {
                    $destination_uuid = Server::ownedByCurrentTeam()->first()->uuid;
                } else {
                    throw new \Exception('No destination set.');
                }
            }
            data_set($config, 'coolify.project_uuid', $project_uuid);
            data_set($config, 'coolify.environment_uuid', $environment_uuid);
            data_set($config, 'coolify.destination_uuid', $destination_uuid);

            $this->config = json_encode($config, JSON_PRETTY_PRINT);

            $config = configValidator($this->config);

            // Get project and environment from config
            $project = \App\Models\Project::where('uuid', data_get($config, 'coolify.project_uuid'))->firstOrFail();
            $environment = \App\Models\Environment::where('uuid', data_get($config, 'coolify.environment_uuid'))->firstOrFail();

            $destination = StandaloneDocker::where('uuid', data_get($config, 'coolify.destination_uuid'))->first();

            if (! $destination) {
                $destination = SwarmDocker::where('uuid', data_get($config, 'coolify.destination_uuid'))->first();
            }

            if (! $destination) {
                throw new \Exception('Destination not found.');
            }
            $server = $destination->server;

            // Validate that environment belongs to project
            if ($environment->project_id !== $project->id) {
                throw new \Exception('Environment does not belong to the specified project.');
            }

            DB::beginTransaction();

            // Create and save the base application first
            $cuid = new Cuid2;
            $application = new Application([
                'name' => data_get($config, 'name', 'New Application'),
                'description' => data_get($config, 'description'),
                'uuid' => $cuid,
                'environment_id' => $environment->id,
                'git_repository' => data_get($config, 'git_repository', 'https://github.com/coollabsio/coolify.git'),
                'git_branch' => data_get($config, 'git_branch', 'main'),
                'build_pack' => data_get($config, 'build.build_pack', 'dockerfile'),
                'ports_exposes' => data_get($config, 'network.ports.expose', '80'),
                'destination_id' => $destination->id,
                'destination_type' => get_class($destination),
                'fqdn' => data_get($config, 'network.fqdn', generateFqdn($server, $cuid)),
            ]);

            $application->save();

            // Create default settings
            $application->settings()->create([]);

            // Now set the full configuration
            $application->setConfig($config);

            DB::commit();

            $this->dispatch('success', 'Application created successfully');

            // Redirect to the application page
            return redirect()->route('project.application.configuration', [
                'project_uuid' => $project->uuid,
                'environment_uuid' => $environment->uuid,
                'application_uuid' => $application->uuid,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            $this->dispatch('error', $e->getMessage());

            return;
        }
    }

    public function render()
    {
        return view('livewire.project.shared.upload-config');
    }
}
