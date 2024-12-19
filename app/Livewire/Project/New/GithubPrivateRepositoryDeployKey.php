<?php

namespace App\Livewire\Project\New;

use App\Models\Application;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use Illuminate\Support\Str;
use Livewire\Component;
use Spatie\Url\Url;

class GithubPrivateRepositoryDeployKey extends Component
{
    public $current_step = 'private_keys';

    public $parameters;

    public $query;

    public $private_keys = [];

    public int $private_key_id;

    public int $port = 3000;

    public string $type;

    public bool $is_static = false;

    public ?string $publish_directory = null;

    // In case of docker compose
    public ?string $base_directory = null;

    public ?string $docker_compose_location = '/docker-compose.yaml';
    // End of docker compose

    public string $repository_url;

    public string $branch;

    public $build_pack = 'nixpacks';

    public bool $show_is_static = true;

    private object $repository_url_parsed;

    private GithubApp|GitlabApp|string $git_source = 'other';

    private ?string $git_host = null;

    private string $git_repository;

    protected $rules = [
        'repository_url' => 'required',
        'branch' => 'required|string',
        'port' => 'required|numeric',
        'is_static' => 'required|boolean',
        'publish_directory' => 'nullable|string',
        'build_pack' => 'required|string',
    ];

    protected $validationAttributes = [
        'repository_url' => 'Repository',
        'branch' => 'Branch',
        'port' => 'Port',
        'is_static' => 'Is static',
        'publish_directory' => 'Publish directory',
        'build_pack' => 'Build pack',
    ];

    public function mount()
    {
        if (isDev()) {
            $this->repository_url = 'https://github.com/coollabsio/coolify-examples';
        }
        $this->parameters = get_route_parameters();
        $this->query = request()->query();
        if (isDev()) {
            $this->private_keys = PrivateKey::where('team_id', currentTeam()->id)->get();
        } else {
            $this->private_keys = PrivateKey::where('team_id', currentTeam()->id)->where('id', '!=', 0)->get();
        }
    }

    public function updatedBuildPack()
    {
        if ($this->build_pack === 'nixpacks') {
            $this->show_is_static = true;
            $this->port = 3000;
        } elseif ($this->build_pack === 'static') {
            $this->show_is_static = false;
            $this->is_static = false;
            $this->port = 80;
        } else {
            $this->show_is_static = false;
            $this->is_static = false;
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
            if (! $destination) {
                $destination = SwarmDocker::where('uuid', $destination_uuid)->first();
            }
            if (! $destination) {
                throw new \Exception('Destination not found. What?!');
            }
            $destination_class = $destination->getMorphClass();

            $this->get_git_source();

            $project = Project::where('uuid', $this->parameters['project_uuid'])->first();
            $environment = $project->load(['environments'])->environments->where('uuid', $this->parameters['environment_uuid'])->first();
            if ($this->git_source === 'other') {
                $application_init = [
                    'name' => generate_random_name(),
                    'git_repository' => $this->git_repository,
                    'git_branch' => $this->branch,
                    'build_pack' => $this->build_pack,
                    'ports_exposes' => $this->port,
                    'publish_directory' => $this->publish_directory,
                    'environment_id' => $environment->id,
                    'destination_id' => $destination->id,
                    'destination_type' => $destination_class,
                    'private_key_id' => $this->private_key_id,
                ];
            } else {
                $application_init = [
                    'name' => generate_random_name(),
                    'git_repository' => $this->git_repository,
                    'git_branch' => $this->branch,
                    'build_pack' => $this->build_pack,
                    'ports_exposes' => $this->port,
                    'publish_directory' => $this->publish_directory,
                    'environment_id' => $environment->id,
                    'destination_id' => $destination->id,
                    'destination_type' => $destination_class,
                    'private_key_id' => $this->private_key_id,
                    'source_id' => $this->git_source->id,
                    'source_type' => $this->git_source->getMorphClass(),
                ];
            }
            if ($this->build_pack === 'dockerfile' || $this->build_pack === 'dockerimage') {
                $application_init['health_check_enabled'] = false;
            }
            if ($this->build_pack === 'dockercompose') {
                $application_init['docker_compose_location'] = $this->docker_compose_location;
                $application_init['base_directory'] = $this->base_directory;
            }
            $application = Application::create($application_init);
            $application->settings->is_static = $this->is_static;
            $application->settings->save();

            $fqdn = generateFqdn($destination->server, $application->uuid);
            $application->fqdn = $fqdn;
            $application->name = generate_random_name($application->uuid);
            $application->save();

            return redirect()->route('project.application.configuration', [
                'application_uuid' => $application->uuid,
                'environment_uuid' => $environment->uuid,
                'project_uuid' => $project->uuid,
            ]);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    private function get_git_source()
    {
        $this->repository_url_parsed = Url::fromString($this->repository_url);
        $this->git_host = $this->repository_url_parsed->getHost();
        $this->git_repository = $this->repository_url_parsed->getSegment(1).'/'.$this->repository_url_parsed->getSegment(2);

        if ($this->git_host === 'github.com') {
            $this->git_source = GithubApp::where('name', 'Public GitHub')->first();

            return;
        }
        if (str($this->repository_url)->startsWith('http')) {
            $this->git_host = $this->repository_url_parsed->getHost();
            $this->git_repository = $this->repository_url_parsed->getSegment(1).'/'.$this->repository_url_parsed->getSegment(2);
            $this->git_repository = Str::finish("git@$this->git_host:$this->git_repository", '.git');
        } else {
            $this->git_repository = $this->repository_url;
        }
        $this->git_source = 'other';
    }
}
