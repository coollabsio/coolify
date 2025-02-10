<?php

namespace App\Livewire\Project\Shared;

use App\Models\Application;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class UploadConfig extends Component
{
    public $config;

    public function mount()
    {
        if (isDev()) {
            $this->setExampleConfig('dockerfile');
        }
    }

    public function setExampleConfig(string $buildPack)
    {
        switch ($buildPack) {
            case 'dockerfile':
                $this->config = '{
    "name": "Example Application",
    "description": "This is an example application configuration",
    "coolify": {
        "project_uuid": "eoc48g0wwsgw48csws8o8c4w",
        "environment_uuid": "q8gs4w44kcs004ogg40cok0k",
        "destination_uuid": "kg4wkc80w8gggso04c4sw40s"
    },
    "build": {
        "build_pack": "dockerfile",
        "docker": {
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
        "project_uuid": "eoc48g0wwsgw48csws8o8c4w",
        "environment_uuid": "q8gs4w44kcs004ogg40cok0k",
        "destination_uuid": "kg4wkc80w8gggso04c4sw40s"
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
        "project_uuid": "eoc48g0wwsgw48csws8o8c4w",
        "environment_uuid": "q8gs4w44kcs004ogg40cok0k",
        "destination_uuid": "kg4wkc80w8gggso04c4sw40s"
    },
     "source": {
        "git_repository": "https://github.com/coollabsio/coolify-examples",
        "git_branch": "main"
    },
    "build": {
        "build_pack": "dockerfile",
        "base_directory": "/dockerfile"
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
    ]
}';
                break;
        }
    }

    public function uploadConfig()
    {
        try {
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
