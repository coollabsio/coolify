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
    public $query;
    public $private_keys;
    public int $private_key_id;
    public string $repository_url;

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
        $this->parameters = get_parameters();
        $this->query = request()->query();
        $this->private_keys = PrivateKey::where('team_id', session('currentTeam')->id)->get();
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
        try {
            $this->validate();
            $url = Url::fromString($this->repository_url);
            $git_host = $url->getHost();
            $git_repository = $url->getSegment(1) . '/' . $url->getSegment(2);
            $git_branch = $url->getSegment(4) ?? 'main';

            $destination_uuid = $this->query['destination'];
            $destination = StandaloneDocker::where('uuid', $destination_uuid)->first();
            if (!$destination) {
                $destination = SwarmDocker::where('uuid', $destination_uuid)->first();
            }
            if (!$destination) {
                throw new \Exception('Destination not found. What?!');
            }
            $destination_class = $destination->getMorphClass();

            $project = Project::where('uuid', $this->parameters['project_uuid'])->first();
            $environment = $project->load(['environments'])->environments->where('name', $this->parameters['environment_name'])->first();
            $application_init = [
                'name' => generate_random_name(),
                'git_repository' => $git_repository,
                'git_branch' => $git_branch,
                'git_full_url' => "git@$git_host:$git_repository.git",
                'build_pack' => 'nixpacks',
                'ports_exposes' => $this->port,
                'publish_directory' => $this->publish_directory,
                'environment_id' => $environment->id,
                'destination_id' => $destination->id,
                'destination_type' => $destination_class,
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
        } catch (\Exception $e) {
            return general_error_handler(err: $e, that: $this);
        }
    }
}
