<?php

namespace App\Http\Livewire\Project\New;

use App\Models\Application;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\Project;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Spatie\Url\Url;

class PublicGitRepository extends Component
{
    public string $repository_url;
    private object $repository_url_parsed;

    public int $port = 3000;
    public string $type;
    public $parameters;
    public $query;

    public bool $branch_found = false;
    public string $selected_branch = 'main';
    public bool $is_static = false;
    public string|null $publish_directory = null;
    public string $git_branch;
    public int $rate_limit_remaining = 0;
    public int $rate_limit_reset = 0;

    private GithubApp|GitlabApp $git_source;
    private string $git_host;
    private string $git_repository;

    protected $rules = [
        'repository_url' => 'required|url',
        'port' => 'required|numeric',
        'is_static' => 'required|boolean',
        'publish_directory' => 'nullable|string',
    ];
    protected $validationAttributes = [
        'repository_url' => 'repository',
        'port' => 'port',
        'is_static' => 'static',
        'publish_directory' => 'publish directory',
    ];
    public function mount()
    {
        if (isDev()) {
            $this->repository_url = 'https://github.com/coollabsio/coolify-examples';
            $this->port = 3000;
        }
        $this->parameters = getRouteParameters();
        $this->query = request()->query();
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
        $this->emit('success', 'Application settings updated!');
    }
    public function load_branch()
    {
        $this->branch_found = false;
        $this->validate([
            'repository_url' => 'required|url'
        ]);
        $this->get_git_source();

        try {
            ['data' => $data, 'rate_limit_remaining' => $this->rate_limit_remaining, 'rate_limit_reset' => $this->rate_limit_reset] = git_api(source: $this->git_source, endpoint: "/repos/{$this->git_repository}/branches/{$this->git_branch}");
            $this->branch_found = true;
        } catch (\Throwable $e) {
            return general_error_handler(err: $e, that: $this);
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
        } elseif ($this->git_host == 'gitlab.com') {
            $this->git_source = GitlabApp::where('name', 'Public GitLab')->first();
        } elseif ($this->git_host == 'bitbucket.org') {
            // Not supported yet
        }
    }
    public function submit()
    {
        try {
            $this->validate();
            $destination_uuid = $this->query['destination'];
            $project_uuid = $this->parameters['project_uuid'];
            $environment_name = $this->parameters['environment_name'];

            $this->get_git_source();
            $this->git_branch = $this->selected_branch ?? $this->git_branch;

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


            $application_init = [
                'name' => generate_application_name($this->git_repository, $this->git_branch),
                'git_repository' => $this->git_repository,
                'git_branch' => $this->git_branch,
                'build_pack' => 'nixpacks',
                'ports_exposes' => $this->port,
                'publish_directory' => $this->publish_directory,
                'environment_id' => $environment->id,
                'destination_id' => $destination->id,
                'destination_type' => $destination_class,
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
}
