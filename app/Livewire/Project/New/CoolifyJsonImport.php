<?php

namespace App\Livewire\Project\New;

use App\Models\Application;
use App\Models\GithubApp;
use App\Models\Project;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class CoolifyJsonImport extends Component
{
    public string $coolifyJson = '';

    public array $parameters;

    public array $query;

    public ?array $parsedConfig = null;

    public ?string $parseError = null;

    public function mount()
    {
        $this->parameters = get_route_parameters();
        $this->query = request()->query();
        if (isDev()) {
            $this->coolifyJson = json_encode([
                'version' => '1.0',
                'name' => 'My App',
                'source' => [
                    'repository' => 'https://github.com/coollabsio/coolify-examples',
                    'branch' => 'main',
                ],
                'build' => [
                    'type' => 'nixpacks',
                ],
            ], JSON_PRETTY_PRINT);
        }
    }

    public function updatedCoolifyJson()
    {
        $this->parseError = null;
        $this->parsedConfig = null;

        if (empty(trim($this->coolifyJson))) {
            return;
        }

        try {
            $config = json_decode($this->coolifyJson, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->parseError = 'Invalid JSON: '.json_last_error_msg();

                return;
            }
            $this->parsedConfig = $config;
        } catch (\Exception $e) {
            $this->parseError = 'Error parsing JSON: '.$e->getMessage();
        }
    }

    public function submit()
    {
        $this->validate([
            'coolifyJson' => 'required',
        ]);

        // Parse the JSON
        $config = json_decode($this->coolifyJson, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->dispatch('error', 'Invalid JSON format: '.json_last_error_msg());

            return;
        }

        // Validate required fields
        $source = data_get($config, 'source', []);
        $repository = data_get($source, 'repository');
        $branch = data_get($source, 'branch', 'main');

        if (empty($repository)) {
            $this->dispatch('error', 'Git repository URL is required in the source section');

            return;
        }

        // Get destination
        $destination_uuid = $this->query['destination'];
        $destination = StandaloneDocker::where('uuid', $destination_uuid)->first();
        if (! $destination) {
            $destination = SwarmDocker::where('uuid', $destination_uuid)->first();
        }
        if (! $destination) {
            throw new \Exception('Destination not found. What?!');
        }
        $destination_class = $destination->getMorphClass();

        // Get project and environment
        $project = Project::where('uuid', $this->parameters['project_uuid'])->first();
        $environment = $project->load(['environments'])->environments->where('uuid', $this->parameters['environment_uuid'])->first();

        // Determine build pack and port
        $buildPack = data_get($config, 'build.type', 'nixpacks');
        $portsExposes = data_get($config, 'domains.ports_exposes');

        // If no explicit port in config, use sensible defaults based on build pack
        // Same logic as other application creation flows (PublicGitRepository, etc.)
        if ($portsExposes === null) {
            $portsExposes = ($buildPack === 'static') ? '80' : '3000';
        }

        // Create the application with basic settings
        $application = Application::create([
            'name' => data_get($config, 'name', 'app-'.new Cuid2),
            'description' => data_get($config, 'description'),
            'repository_project_id' => 0,
            'git_repository' => $repository,
            'git_branch' => $branch,
            'git_commit_sha' => data_get($source, 'commit_sha', 'HEAD'),
            'build_pack' => $buildPack,
            'ports_exposes' => $portsExposes,
            'environment_id' => $environment->id,
            'destination_id' => $destination->id,
            'destination_type' => $destination_class,
            'health_check_enabled' => false,
            'source_id' => 0,
            'source_type' => GithubApp::class,
        ]);

        // Generate FQDN
        $fqdn = generateUrl(server: $destination->server, random: $application->uuid);
        $application->update([
            'fqdn' => $fqdn,
        ]);

        // Apply the full configuration using setConfig
        $application->setConfig($config);

        return redirect()->route('project.application.configuration', [
            'application_uuid' => $application->uuid,
            'environment_uuid' => $environment->uuid,
            'project_uuid' => $project->uuid,
        ]);
    }

    public function render()
    {
        return view('livewire.project.new.coolify-json-import');
    }
}
