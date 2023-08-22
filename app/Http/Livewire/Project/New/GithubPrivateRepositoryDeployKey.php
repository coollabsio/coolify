<?php

namespace App\Http\Livewire\Project\New;

use App\Models\Application;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use Livewire\Component;
use Spatie\Url\Url;

class GithubPrivateRepositoryDeployKey extends Component
{
    public $current_step = 'private_keys';
    public $parameters;
    public $query;
    public $private_keys;
    public int $private_key_id;

    public int $port = 3000;
    public string $type;

    public bool $is_static = false;
    public null|string $publish_directory = null;

    public string $repository_url;
    public string $branch;
    protected $rules = [
        'repository_url' => 'required|url',
        'branch' => 'required|string',
        'port' => 'required|numeric',
        'is_static' => 'required|boolean',
        'publish_directory' => 'nullable|string',
    ];
    protected $validationAttributes = [
        'repository_url' => 'Repository',
        'branch' => 'Branch',
        'port' => 'Port',
        'is_static' => 'Is static',
        'publish_directory' => 'Publish directory',
    ];
    private object $repository_url_parsed;
    private GithubApp|GitlabApp $git_source;
    private string $git_host;
    private string $git_repository;
    private string $git_branch;

    public function mount()
    {
        if (is_dev()) {
            $this->repository_url = 'https://github.com/coollabsio/coolify-examples';
        }
        $this->parameters = get_route_parameters();
        $this->query = request()->query();
        $this->private_keys = PrivateKey::where('team_id', currentTeam()->id)->where('id', '!=', 0)->get();
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
        $this->current_step = 'repository';
    }

    public function submit()
    {
        $this->validate();
        try {
            $destination_uuid = $this->query['destination'];
            $destination = StandaloneDocker::where('uuid', $destination_uuid)->first();
            if (!$destination) {
                $destination = SwarmDocker::where('uuid', $destination_uuid)->first();
            }
            if (!$destination) {
                throw new \Exception('Destination not found. What?!');
            }
            $destination_class = $destination->getMorphClass();

            $this->get_git_source();

            $project = Project::where('uuid', $this->parameters['project_uuid'])->first();
            $environment = $project->load(['environments'])->environments->where('name', $this->parameters['environment_name'])->first();
            $application_init = [
                'name' => generate_random_name(),
                'git_repository' => $this->git_repository,
                'git_branch' => $this->git_branch,
                'git_full_url' => "git@$this->git_host:$this->git_repository.git",
                'build_pack' => 'nixpacks',
                'ports_exposes' => $this->port,
                'publish_directory' => $this->publish_directory,
                'environment_id' => $environment->id,
                'destination_id' => $destination->id,
                'destination_type' => $destination_class,
                'private_key_id' => $this->private_key_id,
                'source_id' => $this->git_source->id,
                'source_type' => $this->git_source->getMorphClass()
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

    private function get_git_source()
    {
        $this->repository_url_parsed = Url::fromString($this->repository_url);
        $this->git_host = $this->repository_url_parsed->getHost();
        $this->git_repository = $this->repository_url_parsed->getSegment(1) . '/' . $this->repository_url_parsed->getSegment(2);
        if ($this->branch) {
            $this->git_branch = $this->branch;
        } else {
            $this->git_branch = $this->repository_url_parsed->getSegment(4) ?? 'main';
        }

        if ($this->git_host == 'github.com') {
            $this->git_source = GithubApp::where('name', 'Public GitHub')->first();
        } elseif ($this->git_host == 'gitlab.com') {
            $this->git_source = GitlabApp::where('name', 'Public GitLab')->first();
        } elseif ($this->git_host == 'bitbucket.org') {
            // Not supported yet
        }
    }
}
