<?php

namespace App\Http\Livewire\Project\New;

use App\Models\Application;
use App\Models\GithubApp;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

class GithubPrivateRepository extends Component
{
    public $github_apps;
    public GithubApp $github_app;
    public $parameters;
    public $query;
    public $type;

    public int $selected_repository_id;
    public string $selected_repository_owner;
    public string $selected_repository_repo;

    public string $selected_branch_name = 'main';

    public string $token;

    protected int $page = 1;

    public $repositories;
    public int $total_repositories_count = 0;

    public $branches;
    public int $total_branches_count = 0;

    protected function loadRepositoryByPage()
    {
        $response = Http::withToken($this->token)->get("{$this->github_app->api_url}/installation/repositories?per_page=100&page={$this->page}");
        $json = $response->json();
        if ($response->status() !== 200) {
            return $this->emit('error', $json['message']);
        }

        if ($json['total_count'] === 0) {
            return;
        }
        $this->total_repositories_count = $json['total_count'];
        $this->repositories = $this->repositories->concat(collect($json['repositories']));
    }
    protected function loadBranchByPage()
    {
        Log::info('Loading page ' . $this->page);
        $response = Http::withToken($this->token)->get("{$this->github_app->api_url}/repos/{$this->selected_repository_owner}/{$this->selected_repository_repo}/branches?per_page=100&page={$this->page}");
        $json = $response->json();
        if ($response->status() !== 200) {
            return $this->emit('error', $json['message']);
        }

        $this->total_branches_count = count($json);
        $this->branches = $this->branches->concat(collect($json));
    }
    public function loadRepositories($github_app_id)
    {
        $this->repositories = collect();
        $this->page = 1;
        $this->github_app = GithubApp::where('id', $github_app_id)->first();
        $this->token = generate_github_installation_token($this->github_app);
        $this->loadRepositoryByPage();
        if ($this->repositories->count() < $this->total_repositories_count) {
            while ($this->repositories->count() < $this->total_repositories_count) {
                $this->page++;
                $this->loadRepositoryByPage();
            }
        }
        $this->selected_repository_id = $this->repositories[0]['id'];
    }
    public function loadBranches()
    {
        $this->selected_repository_owner = $this->repositories->where('id', $this->selected_repository_id)->first()['owner']['login'];
        $this->selected_repository_repo = $this->repositories->where('id', $this->selected_repository_id)->first()['name'];
        $this->branches = collect();
        $this->page = 1;
        $this->loadBranchByPage();
        if ($this->total_branches_count === 100) {
            while ($this->total_branches_count === 100) {
                $this->page++;
                $this->loadBranchByPage();
            }
        }
    }
    public function submit()
    {
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


            $project = Project::where('uuid', $this->parameters['project_uuid'])->first();
            $environment = $project->load(['environments'])->environments->where('name', $this->parameters['environment_name'])->first();

            $application = Application::create([
                'name' => generateRandomName(),
                'repository_project_id' => $this->selected_repository_id,
                'git_repository' => "{$this->selected_repository_owner}/{$this->selected_repository_repo}",
                'git_branch' => $this->selected_branch_name,
                'build_pack' => 'nixpacks',
                'ports_exposes' => '3000',
                'environment_id' => $environment->id,
                'destination_id' => $destination->id,
                'destination_type' => $destination_class,
                'source_id' => $this->github_app->id,
                'source_type' => GithubApp::class,
            ]);

            redirect()->route('project.application.configuration', [
                'application_uuid' => $application->uuid,
                'project_uuid' => $project->uuid,
                'environment_name' => $environment->name
            ]);
        } catch (\Exception $e) {
            return generalErrorHandler($e, $this);
        }
    }
    public function mount()
    {
        $this->parameters = getParameters();
        $this->query = request()->query();
        $this->repositories = $this->branches = collect();
        $this->github_apps = GithubApp::private();
    }
}
