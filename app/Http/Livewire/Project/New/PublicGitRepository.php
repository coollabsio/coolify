<?php

namespace App\Http\Livewire\Project\New;

use App\Models\Application;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\Project;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use Illuminate\Support\Facades\Route;
use Livewire\Component;
use Spatie\Url\Url;

class PublicGitRepository extends Component
{
    public string $public_repository_url;
    public int $port;
    public string $type;
    public $parameters;

    public $servers;
    public $standalone_docker;
    public $swarm_docker;
    public $chosenServer;
    public $chosenDestination;
    public $github_apps;
    public $gitlab_apps;

    public bool $is_static = false;
    public string $publish_directory = '';

    protected $rules = [
        'public_repository_url' => 'required|url',
        'port' => 'required|numeric',
        'is_static' => 'required|boolean',
        'publish_directory' => 'string',
    ];
    public function mount()
    {
        if (env('APP_ENV') === 'local') {
            $this->public_repository_url = 'https://github.com/coollabsio/coolify-examples/tree/nodejs-fastify';
            $this->port = 3000;
        }
        $this->parameters = Route::current()->parameters();
        $this->servers = session('currentTeam')->load(['servers'])->servers;
    }
    public function chooseServer($server_id)
    {
        $this->chosenServer = $server_id;
        $this->standalone_docker = StandaloneDocker::where('server_id', $server_id)->get();
        $this->swarm_docker = SwarmDocker::where('server_id', $server_id)->get();
    }
    public function setDestination($destination_uuid, $destination_type)
    {
        $class = "App\Models\\{$destination_type}";
        $instance = new $class;
        $this->chosenDestination = $instance::where('uuid', $destination_uuid)->first();
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
        $this->validate();
        $url = Url::fromString($this->public_repository_url);
        $git_host = $url->getHost();
        $git_repository = $url->getSegment(1) . '/' . $url->getSegment(2);
        $git_branch = $url->getSegment(4) ?? 'main';

        if ($this->type === 'project') {
            $project = Project::create([
                'name' => fake()->company(),
                'description' => fake()->sentence(),
                'team_id' => session('currentTeam')->id,
            ]);
            $environment = $project->environments->first();
        } else {
            $project = Project::where('uuid', $this->parameters['project_uuid'])->firstOrFail();
            $environment = $project->environments->where('name', $this->parameters['environment_name'])->firstOrFail();
        }
        $application_init = [
            'name' => fake()->words(2, true),
            'git_repository' => $git_repository,
            'git_branch' => $git_branch,
            'build_pack' => 'nixpacks',
            'ports_exposes' => $this->port,
            'publish_directory' => $this->publish_directory,
            'environment_id' => $environment->id,
            'destination_id' => $this->chosenDestination->id,
            'destination_type' => $this->chosenDestination->getMorphClass(),
        ];
        if ($git_host == 'github.com') {
            $application_init['source_id'] = GithubApp::where('name', 'Public GitHub')->first()->id;
            $application_init['source_type'] = GithubApp::class;
        } elseif ($git_host == 'gitlab.com') {
            $application_init['source_id'] = GitlabApp::where('name', 'Public GitLab')->first()->id;
            $application_init['source_type'] = GitlabApp::class;
        } elseif ($git_host == 'bitbucket.org') {
        }
        $application = Application::create($application_init);
        $application->settings->is_static = $this->is_static;
        $application->settings->save();

        return redirect()->route('project.application.configuration', [
            'project_uuid' => $project->uuid,
            'environment_name' => $environment->name,
            'application_uuid' => $application->uuid,
        ]);
    }
}
