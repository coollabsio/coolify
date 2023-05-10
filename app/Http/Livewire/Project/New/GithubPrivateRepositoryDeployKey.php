<?php

namespace App\Http\Livewire\Project\New;

use App\Models\Application;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use Livewire\Component;
use Spatie\Url\Url;

class GithubPrivateRepositoryDeployKey extends Component
{
    public $parameters;
    public $private_keys;
    public int $private_key_id;
    public string $repository_url;

    public $servers;
    public $standalone_docker;
    public $swarm_docker;
    public $chosenServer;
    public $chosenDestination;

    public int $port = 3000;
    public string $type;

    public bool $is_static = false;
    public null|string $publish_directory = null;
    protected $rules = [
        'repository_url' => 'required|url',
        'port' => 'required|numeric',
        'is_static' => 'required|boolean',
        'publish_directory' => 'nullable|string',
    ];
    public function mount()
    {
        if (config('app.env') === 'local') {
            $this->repository_url = 'https://github.com/coollabsio/coolify-examples/tree/nodejs-fastify';
        }
        $this->parameters = getParameters();
        $this->private_keys = PrivateKey::where('team_id', session('currentTeam')->id)->get();
        $this->servers = session('currentTeam')->load(['servers'])->servers;
    }
    public function chooseServer($server)
    {
        $this->chosenServer = $server;
        $this->standalone_docker = StandaloneDocker::where('server_id', $server['id'])->get();
        $this->swarm_docker = SwarmDocker::where('server_id', $server['id'])->get();
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
    public function setPrivateKey($private_key_id)
    {
        $this->private_key_id = $private_key_id;
    }
    public function submit()
    {
        $this->validate();
        $url = Url::fromString($this->repository_url);
        $git_host = $url->getHost();
        $git_repository = $url->getSegment(1) . '/' . $url->getSegment(2);
        $git_branch = $url->getSegment(4) ?? 'main';

        if ($this->type === 'project') {
            $project = Project::create([
                'name' => generateRandomName(),
                'team_id' => session('currentTeam')->id,
            ]);
            $environment = $project->environments->first();
        } else {
            $project = Project::where('uuid', $this->parameters['project_uuid'])->firstOrFail();
            $environment = $project->environments->where('name', $this->parameters['environment_name'])->firstOrFail();
        }
        $application_init = [
            'name' => generateRandomName(),
            'git_repository' => $git_repository,
            'git_branch' => $git_branch,
            'git_full_url' => "git@$git_host:$git_repository.git",
            'build_pack' => 'nixpacks',
            'ports_exposes' => $this->port,
            'publish_directory' => $this->publish_directory,
            'environment_id' => $environment->id,
            'destination_id' => $this->chosenDestination->id,
            'destination_type' => $this->chosenDestination->getMorphClass(),
            'private_key_id' => $this->private_key_id,
        ];
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
