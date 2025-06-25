<?php

namespace App\Livewire\Project\New;

use App\Models\Application;
use App\Models\Project;
use Livewire\Component;
use Spatie\Url\Url;
use Visus\Cuid2\Cuid2;

class GithubPrivateRepositoryHttpsAuth extends Component
{
    public string $current_step = 'credentials';
    public int $project_id;
    public int $environment_id;
    
    public string $git_repository;
    public string $git_branch = 'main';
    public ?string $git_username = null;
    public ?string $git_password = null;
    
    public string $build_pack = 'nixpacks';
    public int $port = 3000;
    public ?string $publish_directory = null;
    public ?string $base_directory = null;
    public ?string $dockerfile = null;
    public ?string $docker_compose_location = null;
    public ?string $docker_compose_custom_start_command = null;
    public ?string $docker_compose_custom_build_command = null;
    public bool $is_static = false;
    
    protected $rules = [
        'git_repository' => 'required|url',
        'git_branch' => 'required|string',
        'git_username' => 'required|string',
        'git_password' => 'required|string',
        'build_pack' => 'required|string',
        'port' => 'required|numeric',
        'is_static' => 'required|boolean',
        'publish_directory' => 'nullable|string',
        'base_directory' => 'nullable|string',
        'dockerfile' => 'nullable|string',
        'docker_compose_location' => 'nullable|string',
        'docker_compose_custom_start_command' => 'nullable|string',
        'docker_compose_custom_build_command' => 'nullable|string',
    ];
    
    protected $validationAttributes = [
        'git_repository' => 'repository URL',
        'git_branch' => 'branch',
        'git_username' => 'username',
        'git_password' => 'password',
        'build_pack' => 'build pack',
        'port' => 'port',
        'is_static' => 'static',
        'publish_directory' => 'publish directory',
        'base_directory' => 'base directory',
        'dockerfile' => 'dockerfile location',
        'docker_compose_location' => 'docker compose location',
        'docker_compose_custom_start_command' => 'docker compose custom start command',
        'docker_compose_custom_build_command' => 'docker compose custom build command',
    ];
    
    public function mount()
    {
        $parameters = get_route_parameters();
        $this->project_id = data_get($parameters, 'project_id');
        $this->environment_id = data_get($parameters, 'environment_id');
    }
    
    public function next()
    {
        if ($this->current_step === 'credentials') {
            $this->validate([
                'git_username' => 'required|string',
                'git_password' => 'required|string',
            ]);
            $this->current_step = 'repository';
        }
    }
    
    public function instantSave()
    {
        if ($this->is_static) {
            $this->port = 80;
            $this->publish_directory = '/dist';
        } else {
            $this->port = 3000;
            $this->publish_directory = null;
        }
    }
    
    public function submit()
    {
        try {
            $this->validate();
            
            // Validate that the URL is HTTPS
            $parsed_url = parse_url($this->git_repository);
            if (!$parsed_url || !isset($parsed_url['scheme']) || $parsed_url['scheme'] !== 'https') {
                throw new \Exception('HTTPS basic auth requires an HTTPS repository URL.');
            }
            
            $project = Project::findOrFail($this->project_id);
            $environment = $project->environments()->find($this->environment_id);
            
            if (!$environment) {
                return redirect()->route('project.index', ['project_uuid' => $project->uuid]);
            }
            
            $destination = $environment->destination->getMorphClass()::where('id', $environment->destination->id)->first();
            
            $application = Application::create([
                'name' => generate_application_name($this->git_repository, $this->git_branch),
                'git_repository' => $this->git_repository,
                'git_branch' => $this->git_branch,
                'git_auth_type' => 'https_basic',
                'git_basic_auth_username' => $this->git_username,
                'git_basic_auth_password' => $this->git_password,
                'build_pack' => $this->build_pack,
                'ports_exposes' => $this->port,
                'publish_directory' => $this->publish_directory,
                'base_directory' => $this->base_directory,
                'environment_id' => $this->environment_id,
                'destination_type' => $destination->getMorphClass(),
                'destination_id' => $destination->id,
            ]);
            
            $fqdn = generateFqdn($destination->server, $application->uuid);
            $application->update([
                'fqdn' => $fqdn,
            ]);
            
            if ($this->build_pack === 'static') {
                $application->settings->is_static = $this->is_static;
                $application->settings->save();
            }
            
            if ($this->build_pack === 'dockerfile') {
                $application->update([
                    'dockerfile' => $this->dockerfile,
                ]);
            }
            
            if ($this->build_pack === 'dockercompose') {
                $application->update([
                    'docker_compose_location' => $this->docker_compose_location,
                    'docker_compose_custom_start_command' => $this->docker_compose_custom_start_command,
                    'docker_compose_custom_build_command' => $this->docker_compose_custom_build_command,
                ]);
            }
            
            return redirect()->route('project.application.configuration', [
                'project_uuid' => $project->uuid,
                'environment_name' => $environment->name,
                'application_uuid' => $application->uuid,
            ]);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
    
    public function render()
    {
        return view('livewire.project.new.github-private-repository-https-auth');
    }
}