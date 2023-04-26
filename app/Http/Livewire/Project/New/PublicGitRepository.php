<?php

namespace App\Http\Livewire\Project\New;

use App\Http\Livewire\Application\Destination;
use App\Models\Application;
use App\Models\Git;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use Livewire\Component;
use Spatie\Url\Url;

class PublicGitRepository extends Component
{
    public string $public_repository_url;
    public int $port;

    public $servers;
    public $standalone_docker;
    public $swarm_docker;
    public $chosenServer;
    public $chosenDestination;
    public $is_static = false;
    public $github_apps;
    public $gitlab_apps;

    protected $rules = [
        'public_repository_url' => 'required|url',
        'port' => 'required|numeric',
        'is_static' => 'required|boolean',
    ];
    public function mount()
    {
        if (env('APP_ENV') === 'local') {
            $this->public_repository_url = 'https://github.com/coollabsio/coolify-examples/tree/nodejs-fastify';
            $this->port = 3000;
        }
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

    public function submit()
    {
        $this->validate();

        $url = Url::fromString($this->public_repository_url);
        $git_host = $url->getHost();
        $git_repository = $url->getSegment(1) . '/' . $url->getSegment(2);
        $git_branch = $url->getSegment(4) ?? 'main';

        $project = Project::create([
            'name' => fake()->company(),
            'description' => fake()->sentence(),
            'team_id' => session('currentTeam')->id,
        ]);
        $environment = $project->environments->first();
        $application_init = [
            'name' => fake()->words(2, true),
            'git_repository' => $git_repository,
            'git_branch' => $git_branch,
            'build_pack' => 'nixpacks',
            'ports_exposes' => $this->port,
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

        return redirect()->route('project.application.configuration', [
            'project_uuid' => $project->uuid,
            'environment_name' => $environment->name,
            'application_uuid' => $application->uuid,
        ]);
    }
}
