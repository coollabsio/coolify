<?php

namespace App\Livewire\Project\New;

use App\Models\Application;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\Project;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use Carbon\Carbon;
use Livewire\Component;
use Spatie\Url\Url;

class PublicGitRepository extends Component
{
    public string $repository_url;

    public int $port = 3000;

    public string $type;

    public $parameters;

    public $query;

    public bool $branchFound = false;

    public string $selectedBranch = 'main';

    public bool $isStatic = false;

    public bool $checkCoolifyConfig = true;

    public ?string $publish_directory = null;

    // In case of docker compose
    public string $base_directory = '/';

    public ?string $docker_compose_location = '/docker-compose.yaml';
    // End of docker compose

    public string $git_branch = 'main';

    public int $rate_limit_remaining = 0;

    public $rate_limit_reset = 0;

    private object $repository_url_parsed;

    public GithubApp|GitlabApp|string $git_source = 'other';

    public string $git_host;

    public string $git_repository;

    public $build_pack = 'nixpacks';

    public bool $show_is_static = true;

    public bool $new_compose_services = false;

    protected $rules = [
        'repository_url' => 'required|url',
        'port' => 'required|numeric',
        'isStatic' => 'required|boolean',
        'publish_directory' => 'nullable|string',
        'build_pack' => 'required|string',
        'base_directory' => 'nullable|string',
        'docker_compose_location' => 'nullable|string',
    ];

    protected $validationAttributes = [
        'repository_url' => 'repository',
        'port' => 'port',
        'isStatic' => 'static',
        'publish_directory' => 'publish directory',
        'build_pack' => 'build pack',
        'base_directory' => 'base directory',
        'docker_compose_location' => 'docker compose location',
    ];

    public function mount()
    {
        if (isDev()) {
            $this->repository_url = 'https://github.com/coollabsio/coolify-examples';
            $this->port = 3000;
        }
        $this->parameters = get_route_parameters();
        $this->query = request()->query();
    }

    public function updatedBaseDirectory()
    {
        if ($this->base_directory) {
            $this->base_directory = rtrim($this->base_directory, '/');
            if (! str($this->base_directory)->startsWith('/')) {
                $this->base_directory = '/'.$this->base_directory;
            }
        }
    }

    public function updatedDockerComposeLocation()
    {
        if ($this->docker_compose_location) {
            $this->docker_compose_location = rtrim($this->docker_compose_location, '/');
            if (! str($this->docker_compose_location)->startsWith('/')) {
                $this->docker_compose_location = '/'.$this->docker_compose_location;
            }
        }
    }

    public function updatedBuildPack()
    {
        if ($this->build_pack === 'nixpacks') {
            $this->show_is_static = true;
            $this->port = 3000;
        } elseif ($this->build_pack === 'static') {
            $this->show_is_static = false;
            $this->isStatic = false;
            $this->port = 80;
        } else {
            $this->show_is_static = false;
            $this->isStatic = false;
        }
    }

    public function instantSave()
    {
        if ($this->isStatic) {
            $this->port = 80;
            $this->publish_directory = '/dist';
        } else {
            $this->port = 3000;
            $this->publish_directory = null;
        }
        $this->dispatch('success', 'Application settings updated!');
    }

    public function loadBranch()
    {
        try {
            if (str($this->repository_url)->startsWith('git@')) {
                $github_instance = str($this->repository_url)->after('git@')->before(':');
                $repository = str($this->repository_url)->after(':')->before('.git');
                $this->repository_url = 'https://'.str($github_instance).'/'.$repository;
            }
            if (
                (str($this->repository_url)->startsWith('https://') ||
                    str($this->repository_url)->startsWith('http://')) &&
                ! str($this->repository_url)->endsWith('.git') &&
                (! str($this->repository_url)->contains('github.com') ||
                    ! str($this->repository_url)->contains('git.sr.ht'))
            ) {
                $this->repository_url = $this->repository_url.'.git';
            }
            if (str($this->repository_url)->contains('github.com') && str($this->repository_url)->endsWith('.git')) {
                $this->repository_url = str($this->repository_url)->beforeLast('.git')->value();
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
        try {
            $this->branchFound = false;
            $this->getGitSource();
            $this->getBranch();
            $this->selectedBranch = $this->git_branch;
        } catch (\Throwable $e) {
            if ($this->rate_limit_remaining == 0) {
                $this->selectedBranch = $this->git_branch;
                $this->branchFound = true;

                return;
            }
            if (! $this->branchFound && $this->git_branch === 'main') {
                try {
                    $this->git_branch = 'master';
                    $this->getBranch();
                } catch (\Throwable $e) {
                    return handleError($e, $this);
                }
            } else {
                return handleError($e, $this);
            }
        }
    }

    private function getGitSource()
    {
        $this->git_branch = 'main';
        $this->base_directory = '/';

        $this->repository_url_parsed = Url::fromString($this->repository_url);
        $this->git_host = $this->repository_url_parsed->getHost();
        $this->git_repository = $this->repository_url_parsed->getSegment(1).'/'.$this->repository_url_parsed->getSegment(2);

        if ($this->repository_url_parsed->getSegment(3) === 'tree') {
            $path = str($this->repository_url_parsed->getPath())->trim('/');
            $this->git_branch = str($path)->after('tree/')->before('/')->value();
            $this->base_directory = str($path)->after($this->git_branch)->after('/')->value();
            if (filled($this->base_directory)) {
                $this->base_directory = '/'.$this->base_directory;
            } else {
                $this->base_directory = '/';
            }
        } else {
            $this->git_branch = 'main';
        }
        if ($this->git_host === 'github.com') {
            $this->git_source = GithubApp::where('name', 'Public GitHub')->first();

            return;
        }
        $this->git_repository = $this->repository_url;
        $this->git_source = 'other';
    }

    private function getBranch()
    {
        if ($this->git_source === 'other') {
            $this->branchFound = true;

            return;
        }
        if ($this->git_source->getMorphClass() === \App\Models\GithubApp::class) {
            ['rate_limit_remaining' => $this->rate_limit_remaining, 'rate_limit_reset' => $this->rate_limit_reset] = githubApi(source: $this->git_source, endpoint: "/repos/{$this->git_repository}/branches/{$this->git_branch}");
            $this->rate_limit_reset = Carbon::parse((int) $this->rate_limit_reset)->format('Y-M-d H:i:s');
            $this->branchFound = true;
        }
    }

    public function submit()
    {
        try {
            $this->validate();
            $destination_uuid = $this->query['destination'];
            $project_uuid = $this->parameters['project_uuid'];
            $environment_uuid = $this->parameters['environment_uuid'];

            $destination = StandaloneDocker::where('uuid', $destination_uuid)->first();
            if (! $destination) {
                $destination = SwarmDocker::where('uuid', $destination_uuid)->first();
            }
            if (! $destination) {
                throw new \Exception('Destination not found. What?!');
            }
            $destination_class = $destination->getMorphClass();

            $project = Project::where('uuid', $project_uuid)->first();
            $environment = $project->load(['environments'])->environments->where('uuid', $environment_uuid)->first();

            if ($this->build_pack === 'dockercompose' && isDev() && $this->new_compose_services) {
                $server = $destination->server;
                $new_service = [
                    'name' => 'service'.str()->random(10),
                    'docker_compose_raw' => 'coolify',
                    'environment_id' => $environment->id,
                    'server_id' => $server->id,
                ];
                if ($this->git_source === 'other') {
                    $new_service['git_repository'] = $this->git_repository;
                    $new_service['git_branch'] = $this->git_branch;
                } else {
                    $new_service['git_repository'] = $this->git_repository;
                    $new_service['git_branch'] = $this->git_branch;
                    $new_service['source_id'] = $this->git_source->id;
                    $new_service['source_type'] = $this->git_source->getMorphClass();
                }
                $service = Service::create($new_service);

                return redirect()->route('project.service.configuration', [
                    'service_uuid' => $service->uuid,
                    'environment_uuid' => $environment->uuid,
                    'project_uuid' => $project->uuid,
                ]);

                return;
            }
            if ($this->git_source === 'other') {
                $application_init = [
                    'name' => generate_random_name(),
                    'git_repository' => $this->git_repository,
                    'git_branch' => $this->git_branch,
                    'ports_exposes' => $this->port,
                    'publish_directory' => $this->publish_directory,
                    'environment_id' => $environment->id,
                    'destination_id' => $destination->id,
                    'destination_type' => $destination_class,
                    'build_pack' => $this->build_pack,
                    'base_directory' => $this->base_directory,
                ];
            } else {
                $application_init = [
                    'name' => generate_application_name($this->git_repository, $this->git_branch),
                    'git_repository' => $this->git_repository,
                    'git_branch' => $this->git_branch,
                    'ports_exposes' => $this->port,
                    'publish_directory' => $this->publish_directory,
                    'environment_id' => $environment->id,
                    'destination_id' => $destination->id,
                    'destination_type' => $destination_class,
                    'source_id' => $this->git_source->id,
                    'source_type' => $this->git_source->getMorphClass(),
                    'build_pack' => $this->build_pack,
                    'base_directory' => $this->base_directory,
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

            $application->settings->is_static = $this->isStatic;
            $application->settings->save();
            $fqdn = generateFqdn($destination->server, $application->uuid);
            $application->fqdn = $fqdn;
            $application->save();
            if ($this->checkCoolifyConfig) {
                // $config = loadConfigFromGit($this->repository_url, $this->git_branch, $this->base_directory, $this->query['server_id'], auth()->user()->currentTeam()->id);
                // if ($config) {
                //     $application->setConfig($config);
                // }
            }

            return redirect()->route('project.application.configuration', [
                'application_uuid' => $application->uuid,
                'environment_uuid' => $environment->uuid,
                'project_uuid' => $project->uuid,
            ]);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
