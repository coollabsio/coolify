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
    public bool $branch_found = false;
    public string $selected_branch = 'main';
    public bool $is_static = false;
    public string|null $publish_directory = null;
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
        'is_static' => 'required|boolean',
        'publish_directory' => 'nullable|string',
        'build_pack' => 'required|string',
    ];
    protected $validationAttributes = [
        'repository_url' => 'repository',
        'port' => 'port',
        'is_static' => 'static',
        'publish_directory' => 'publish directory',
        'build_pack' => 'build pack',
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
    public function updatedBuildPack()
    {
        if ($this->build_pack === 'nixpacks') {
            $this->show_is_static = true;
            $this->port = 3000;
        } else if ($this->build_pack === 'static') {
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
        $this->dispatch('success', 'Application settings updated!');
    }
    public function load_any_git()
    {
        $this->branch_found = true;
    }
    public function load_branch()
    {
        try {
            if (str($this->repository_url)->startsWith('git@')) {
                $github_instance = str($this->repository_url)->after('git@')->before(':');
                $repository = str($this->repository_url)->after(':')->before('.git');
                $this->repository_url = 'https://' . str($github_instance) . '/' . $repository;
            }
            if (
                (str($this->repository_url)->startsWith('https://') ||
                    str($this->repository_url)->startsWith('http://')) &&
                !str($this->repository_url)->endsWith('.git') &&
                (!str($this->repository_url)->contains('github.com') ||
                    !str($this->repository_url)->contains('git.sr.ht'))
            ) {
                $this->repository_url = $this->repository_url . '.git';
            }
            if (str($this->repository_url)->contains('github.com')) {
                $this->repository_url = str($this->repository_url)->before('.git')->value();
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
        try {
            $this->branch_found = false;
            $this->get_git_source();
            $this->get_branch();
            $this->selected_branch = $this->git_branch;
        } catch (\Throwable $e) {
            ray($e->getMessage());
            if (!$this->branch_found && $this->git_branch == 'main') {
                try {
                    $this->git_branch = 'master';
                    $this->get_branch();
                } catch (\Throwable $e) {
                    return handleError($e, $this);
                }
            } else {
                return handleError($e, $this);
            }
        }
    }

    private function get_git_source()
    {
        $this->repository_url_parsed = Url::fromString($this->repository_url);
        $this->git_host = $this->repository_url_parsed->getHost();
        $this->git_repository = $this->repository_url_parsed->getSegment(1) . '/' . $this->repository_url_parsed->getSegment(2);
        $this->git_branch = $this->repository_url_parsed->getSegment(4) ?? 'main';

        if ($this->git_host == 'github.com') {
            $this->git_source = GithubApp::where('name', 'Public GitHub')->first();
            return;
        }
        $this->git_repository = $this->repository_url;
        $this->git_source = 'other';
    }

    private function get_branch()
    {
        if ($this->git_source === 'other') {
            $this->branch_found = true;
            return;
        }
        if ($this->git_source->getMorphClass() === 'App\Models\GithubApp') {
            ['rate_limit_remaining' => $this->rate_limit_remaining, 'rate_limit_reset' => $this->rate_limit_reset] = githubApi(source: $this->git_source, endpoint: "/repos/{$this->git_repository}/branches/{$this->git_branch}");
            $this->rate_limit_reset = Carbon::parse((int)$this->rate_limit_reset)->format('Y-M-d H:i:s');
            $this->branch_found = true;
        }
    }

    public function submit()
    {
        try {
            $this->validate();
            $destination_uuid = $this->query['destination'];
            $project_uuid = $this->parameters['project_uuid'];
            $environment_name = $this->parameters['environment_name'];

            $destination = StandaloneDocker::where('uuid', $destination_uuid)->first();
            if (!$destination) {
                $destination = SwarmDocker::where('uuid', $destination_uuid)->first();
            }
            if (!$destination) {
                throw new \Exception('Destination not found. What?!');
            }
            $destination_class = $destination->getMorphClass();

            $project = Project::where('uuid', $project_uuid)->first();
            $environment = $project->load(['environments'])->environments->where('name', $environment_name)->first();

            if ($this->build_pack === 'dockercompose' && isDev() && $this->new_compose_services ) {
                $server = $destination->server;
                $new_service  = [
                    'name' => 'service' . str()->random(10),
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
                    'environment_name' => $environment->name,
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
                ];
            }

            if ($this->build_pack === 'dockerfile' || $this->build_pack === 'dockerimage') {
                $application_init['health_check_enabled'] = false;
            }
            $application = Application::create($application_init);

            $application->settings->is_static = $this->is_static;
            $application->settings->save();

            $fqdn = generateFqdn($destination->server, $application->uuid);
            $application->fqdn = $fqdn;
            $application->save();

            return redirect()->route('project.application.configuration', [
                'application_uuid' => $application->uuid,
                'environment_name' => $environment->name,
                'project_uuid' => $project->uuid,
            ]);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
