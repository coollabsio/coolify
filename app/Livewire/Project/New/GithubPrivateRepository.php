<?php

namespace App\Livewire\Project\New;

use App\Models\Application;
use App\Models\EnvironmentVariable;
use App\Models\GithubApp;
use App\Models\Project;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use App\Rules\ValidGitBranch;
use App\Services\RepositoryDetector;
use App\Support\ValidationPatterns;
use App\Traits\HasRepositoryDetection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Livewire\Component;

class GithubPrivateRepository extends Component
{
    use HasRepositoryDetection;

    public $current_step = 'github_apps';

    public $github_apps;

    public GithubApp $github_app;

    public $parameters;

    public $currentRoute;

    public $query;

    public $type;

    public int $selected_repository_id;

    public int $selected_github_app_id;

    public string $selected_repository_owner;

    public string $selected_repository_repo;

    public string $selected_branch_name = 'main';

    public string $token;

    public $repositories;

    public int $total_repositories_count = 0;

    public $branches;

    public int $total_branches_count = 0;

    public int $port = 3000;

    public bool $is_static = false;

    public ?string $publish_directory = null;

    // In case of docker compose
    public ?string $base_directory = '/';

    public ?string $docker_compose_location = '/docker-compose.yaml';
    // End of docker compose

    protected int $page = 1;

    public $build_pack = 'nixpacks';

    public bool $show_is_static = true;

    public function mount()
    {
        $this->currentRoute = Route::currentRouteName();
        $this->parameters = get_route_parameters();
        $this->query = request()->query();
        $this->repositories = $this->branches = collect();
        $this->github_apps = GithubApp::private();
    }

    public function updatedSelectedRepositoryId(): void
    {
        $this->loadBranches();
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

    public function loadRepositories($github_app_id)
    {
        $this->repositories = collect();
        $this->page = 1;
        $this->selected_github_app_id = $github_app_id;
        $this->github_app = GithubApp::where('id', $github_app_id)->first();
        $this->token = generateGithubInstallationToken($this->github_app);
        $repositories = loadRepositoryByPage($this->github_app, $this->token, $this->page);
        $this->total_repositories_count = $repositories['total_count'];
        $this->repositories = $this->repositories->concat(collect($repositories['repositories']));
        if ($this->repositories->count() < $this->total_repositories_count) {
            while ($this->repositories->count() < $this->total_repositories_count) {
                $this->page++;
                $repositories = loadRepositoryByPage($this->github_app, $this->token, $this->page);
                $this->total_repositories_count = $repositories['total_count'];
                $this->repositories = $this->repositories->concat(collect($repositories['repositories']));
            }
        }
        $this->repositories = $this->repositories->sortBy('name');
        if ($this->repositories->count() > 0) {
            $this->selected_repository_id = data_get($this->repositories->first(), 'id');
        }
        $this->current_step = 'repository';
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
        $this->branches = sortBranchesByPriority($this->branches);
        $this->selected_branch_name = data_get($this->branches, '0.name', 'main');

        $this->detectRepository();
    }

    protected function loadBranchByPage()
    {
        $response = Http::GitHub($this->github_app->api_url, $this->token)
            ->timeout(20)
            ->retry(3, 200, throw: false)
            ->get("/repos/{$this->selected_repository_owner}/{$this->selected_repository_repo}/branches", [
                'per_page' => 100,
                'page' => $this->page,
            ]);
        $json = $response->json();
        if ($response->status() !== 200) {
            return $this->dispatch('error', $json['message']);
        }

        $this->total_branches_count = count($json);
        $this->branches = $this->branches->concat(collect($json));
    }

    public function detectRepository(): void
    {
        $this->detectionRan = false;
        $this->envImported = false;

        try {
            $serverId = data_get($this->query, 'server_id');
            $teamId = currentTeam()->id;

            $repoUrl = "https://github.com/{$this->selected_repository_owner}/{$this->selected_repository_repo}";

            $detector = new RepositoryDetector(
                repositoryUrl: $repoUrl,
                branch: $this->selected_branch_name,
                baseDirectory: $this->base_directory ?? '/',
                serverId: (int) $serverId,
                teamId: $teamId,
            );

            $this->applyDetectionResult($detector->detect());
        } catch (\Throwable $e) {
            Log::debug('Repository detection failed in component', ['error' => $e->getMessage()]);
        }

        $this->detectionRan = true;
    }

    public function submit()
    {
        try {
            // Validate git repository parts and branch
            $validator = validator([
                'selected_repository_owner' => $this->selected_repository_owner,
                'selected_repository_repo' => $this->selected_repository_repo,
                'selected_branch_name' => $this->selected_branch_name,
                'docker_compose_location' => $this->docker_compose_location,
            ], [
                'selected_repository_owner' => 'required|string|regex:/^[a-zA-Z0-9\-_]+$/',
                'selected_repository_repo' => 'required|string|regex:/^[a-zA-Z0-9\-_\.]+$/',
                'selected_branch_name' => ['required', 'string', new ValidGitBranch],
                'docker_compose_location' => ValidationPatterns::filePathRules(),
            ]);

            if ($validator->fails()) {
                throw new \RuntimeException('Invalid repository data: '.$validator->errors()->first());
            }

            $destination_uuid = $this->query['destination'];
            $destination = StandaloneDocker::where('uuid', $destination_uuid)->first();
            if (! $destination) {
                $destination = SwarmDocker::where('uuid', $destination_uuid)->first();
            }
            if (! $destination) {
                throw new \Exception('Destination not found. What?!');
            }
            $destination_class = $destination->getMorphClass();

            $project = Project::ownedByCurrentTeam()->where('uuid', $this->parameters['project_uuid'])->firstOrFail();
            $environment = $project->environments()->where('uuid', $this->parameters['environment_uuid'])->firstOrFail();

            $application_init = [
                'name' => generate_application_name($this->selected_repository_owner.'/'.$this->selected_repository_repo, $this->selected_branch_name),
                'repository_project_id' => $this->selected_repository_id,
                'git_repository' => str($this->selected_repository_owner)->trim()->toString().'/'.str($this->selected_repository_repo)->trim()->toString(),
                'git_branch' => str($this->selected_branch_name)->trim()->toString(),
                'build_pack' => $this->build_pack,
                'ports_exposes' => $this->port,
                'publish_directory' => $this->publish_directory,
                'base_directory' => $this->base_directory,
                'environment_id' => $environment->id,
                'destination_id' => $destination->id,
                'destination_type' => $destination_class,
                'source_id' => $this->github_app->id,
                'source_type' => $this->github_app->getMorphClass(),
            ];

            if ($this->build_pack === 'dockerfile' || $this->build_pack === 'dockerimage') {
                $application_init['health_check_enabled'] = false;
            }
            if ($this->build_pack === 'dockerfile' && $this->selectedDockerfile) {
                if (! empty($this->detectedDockerfiles) && ! in_array($this->selectedDockerfile, $this->detectedDockerfiles, true)) {
                    $this->selectedDockerfile = $this->detectedDockerfiles[0];
                }
                $application_init['dockerfile_location'] = $this->selectedDockerfile;
            }
            if ($this->build_pack === 'dockercompose') {
                if (! empty($this->detectedDockerComposeFiles) && $this->selectedDockerComposeFile
                    && ! in_array($this->selectedDockerComposeFile, $this->detectedDockerComposeFiles, true)) {
                    $this->selectedDockerComposeFile = $this->detectedDockerComposeFiles[0];
                    $this->docker_compose_location = '/'.$this->selectedDockerComposeFile;
                }
                $application_init['docker_compose_location'] = $this->docker_compose_location;
            }

            $application = Application::forceCreate($application_init);
            $application->settings->is_static = $this->is_static;
            $application->settings->save();

            $fqdn = generateUrl(server: $destination->server, random: $application->uuid);
            $application->fqdn = $fqdn;

            $application->name = generate_application_name($this->selected_repository_owner.'/'.$this->selected_repository_repo, $this->selected_branch_name, $application->uuid);
            $application->save();

            // Import environment variables from .env.example
            if ($this->envImported && count($this->envExampleVars) > 0) {
                DB::transaction(function () use ($application): void {
                    foreach ($this->envExampleVars as $key => $value) {
                        EnvironmentVariable::create([
                            'key' => $key,
                            'value' => $value,
                            'resourceable_type' => $application->getMorphClass(),
                            'resourceable_id' => $application->id,
                            'is_preview' => false,
                        ]);
                    }
                });
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
}
