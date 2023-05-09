<?php

namespace App\Http\Livewire\Project\New;

use App\Models\Application;
use App\Models\GithubApp;
use App\Models\Project;
use App\Models\Server;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

class GithubPrivateRepository extends Component
{
    public $github_apps;
    public GithubApp $github_app;
    public $parameters;
    public $type;

    public int $selected_repository_id;
    public string $selected_repository_owner;
    public string $selected_repository_repo;

    public string $selected_branch_name = 'main';

    public int $selected_server_id;
    public int $selected_destination_id;
    public string $selected_destination_class;

    public string $token;

    protected int $page = 1;

    public $servers;
    public $destinations;
    public $repositories;
    public int $total_repositories_count = 0;

    public $branches;
    public int $total_branches_count = 0;

    protected function loadRepositoryByPage()
    {
        Log::info('Loading page ' . $this->page);
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
    public function loadServers()
    {
        $this->servers = Server::validated();
        $this->selected_server_id = $this->servers[0]['id'];
    }
    public function loadDestinations()
    {
        $server = $this->servers->where('id', $this->selected_server_id)->first();
        $this->destinations = $server->standaloneDockers->merge($server->swarmDockers);
        $this->selected_destination_id = $this->destinations[0]['id'];
        $this->selected_destination_class = $this->destinations[0]->getMorphClass();
    }
    public function submit()
    {
        try {
            if ($this->type === 'project') {
                $project = Project::create([
                    'name' => generateRandomName(),
                    'team_id' => session('currentTeam')->id
                ]);
                $environment = $project->load(['environments'])->environments->first();
            } else {
                $project = Project::where('uuid', $this->parameters['project_uuid'])->first();
                $environment = $project->load(['environments'])->environments->where('name', $this->parameters['environment_name'])->first();
            }
            $application = Application::create([
                'name' => "{$this->selected_repository_owner}/{$this->selected_repository_repo}:{$this->selected_branch_name}",
                'project_id' => $this->selected_repository_id,
                'git_repository' => "{$this->selected_repository_owner}/{$this->selected_repository_repo}",
                'git_branch' => $this->selected_branch_name,
                'build_pack' => 'nixpacks',
                'ports_exposes' => '3000',
                'environment_id' => $environment->id,
                'destination_id' => $this->selected_destination_id,
                'destination_type' => $this->selected_destination_class,
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
        $this->repositories = $this->branches = $this->servers = $this->destinations = collect();
        $this->github_apps = GithubApp::private();
    }
}
