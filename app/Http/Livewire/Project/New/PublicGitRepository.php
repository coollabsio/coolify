<?php

namespace App\Http\Livewire\Project\New;

use App\Models\Application;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\Project;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use Livewire\Component;
use Spatie\Url\Url;

class PublicGitRepository extends Component
{
    public string $repository_url;
    public int $port = 3000;
    public string $type;
    public $parameters;
    public $query;

    public $github_apps;
    public $gitlab_apps;

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
            $this->port = 3000;
        }
        $this->parameters = getParameters();
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
        $this->emit('saved', 'Application settings updated!');
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
                'name' => generateRandomName() . "-{$git_repository}:{$git_branch}",
                'git_repository' => $git_repository,
                'git_branch' => $git_branch,
                'build_pack' => 'nixpacks',
                'ports_exposes' => $this->port,
                'publish_directory' => $this->publish_directory,
                'environment_id' => $environment->id,
                'destination_id' => $destination->id,
                'destination_type' => $destination_class,
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
        } catch (\Exception $e) {
            return generalErrorHandler($e);
        }
    }
}
