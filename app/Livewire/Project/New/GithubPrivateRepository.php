<?php

namespace App\Livewire\Project\New;

use App\Models\Application;
use App\Models\GithubApp;
use App\Models\Project;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class GithubPrivateRepository extends Component
{
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

    public string $base_directory = '/';

    public ?string $docker_compose_location = '/docker-compose.yaml';
    // End of docker compose

    protected int $page = 1;

    public $build_pack = 'nixpacks';

    public bool $show_is_static = true;

    public ?string $coolify_config = null;

    public bool $use_coolify_config = false;

    public function mount()
    {
        $this->currentRoute = Route::currentRouteName();
        $this->parameters = get_route_parameters();
        $this->query = request()->query();
        $this->repositories = $this->branches = collect();
        $this->github_apps = GithubApp::private();
    }

    public function updatedBaseDirectory()
    {
        if ($this->base_directory) {
            $this->base_directory = rtrim($this->base_directory, '/');
            if (! str($this->base_directory)->startsWith('/')) {
                $this->base_directory = '/'.$this->base_directory;
            }
        }
        $this->getCoolifyConfig();
    }

    public function updatedSelectedBranchName()
    {
        $this->getCoolifyConfig();
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
        } elseif ($this->build_pack === 'dockercompose') {
            $this->show_is_static = false;
            $this->is_static = false;
        } else {
            $this->show_is_static = true;
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
        $this->loadRepositoryByPage();
        if ($this->repositories->count() < $this->total_repositories_count) {
            while ($this->repositories->count() < $this->total_repositories_count) {
                $this->page++;
                $this->loadRepositoryByPage();
            }
        }
        $this->repositories = $this->repositories->sortBy('name');
        if ($this->repositories->count() > 0) {
            $this->selected_repository_id = data_get($this->repositories->first(), 'id');
        }
        $this->current_step = 'repository';
    }

    protected function loadRepositoryByPage()
    {
        $response = Http::withToken($this->token)->get("{$this->github_app->api_url}/installation/repositories?per_page=100&page={$this->page}");
        $json = $response->json();
        if ($response->status() !== 200) {
            return $this->dispatch('error', $json['message']);
        }

        if ($json['total_count'] === 0) {
            return;
        }
        $this->total_repositories_count = $json['total_count'];
        $this->repositories = $this->repositories->concat(collect($json['repositories']));
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
        $main_branch = $this->branches->firstWhere('name', 'main');
        $master_branch = $this->branches->firstWhere('name', 'master');
        $this->selected_branch_name = $main_branch ? 'main' : ($master_branch ? 'master' : data_get($this->branches, '0.name', 'main'));
        $this->getCoolifyConfig();
    }

    private function getCoolifyConfig()
    {
        try {
            ['coolify_config' => $this->coolify_config] = $this->github_app->getCoolifyConfig($this->base_directory, $this->selected_repository_owner.'/'.$this->selected_repository_repo, $this->selected_branch_name);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    protected function loadBranchByPage()
    {
        $response = Http::withToken($this->token)->get("{$this->github_app->api_url}/repos/{$this->selected_repository_owner}/{$this->selected_repository_repo}/branches?per_page=100&page={$this->page}");
        $json = $response->json();
        if ($response->status() !== 200) {
            return $this->dispatch('error', $json['message']);
        }

        $this->total_branches_count = count($json);
        $this->branches = $this->branches->concat(collect($json));
    }

    public function submit()
    {
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

            $project = Project::where('uuid', $this->parameters['project_uuid'])->first();
            $environment = $project->load(['environments'])->environments->where('uuid', $this->parameters['environment_uuid'])->first();

            if (filled($this->coolify_config) && $this->use_coolify_config) {
                try {
                    $config = json_decode($this->coolify_config, true);
                    data_set($config, 'coolify.destination_uuid', $destination->uuid);
                    data_set($config, 'coolify.project_uuid', $project->uuid);
                    data_set($config, 'coolify.environment_uuid', $environment->uuid);
                    data_set($config, 'source.git_repository', "{$this->selected_repository_owner}/{$this->selected_repository_repo}");
                    data_set($config, 'source.git_branch', $this->selected_branch_name);
                    $this->coolify_config = json_encode($config, JSON_PRETTY_PRINT, JSON_UNESCAPED_SLASHES);
                    $config = configValidator($this->coolify_config);
                    DB::beginTransaction();

                    // Create and save the base application first
                    $cuid = new Cuid2;
                    $application = new Application([
                        'name' => generate_application_name($this->selected_repository_owner.'/'.$this->selected_repository_repo, $this->selected_branch_name),
                        'repository_project_id' => $this->selected_repository_id,
                        'description' => data_get($config, 'description'),
                        'uuid' => $cuid,
                        'environment_id' => $environment->id,
                        'git_repository' => data_get($config, 'source.git_repository', "{$this->selected_repository_owner}/{$this->selected_repository_repo}"),
                        'git_branch' => data_get($config, 'source.git_branch', $this->selected_branch_name),
                        'build_pack' => data_get($config, 'build.build_pack', $this->build_pack),
                        'ports_exposes' => data_get($config, 'network.ports.expose', $this->port),
                        'destination_id' => $destination->id,
                        'destination_type' => get_class($destination),
                        'source_id' => $this->github_app->id,
                        'source_type' => $this->github_app->getMorphClass(),
                        'fqdn' => data_get($config, 'network.fqdn', generateFqdn($destination->server, $cuid)),
                        'base_directory' => data_get($config, 'build.base_directory', $this->base_directory),
                        'publish_directory' => data_get($config, 'build.publish_directory', $this->publish_directory),
                    ]);

                    $application->save();

                    // Create default settings
                    $application->settings()->create([]);

                    // Now set the full configuration
                    $application->setConfig($config);

                    DB::commit();

                    $this->dispatch('success', 'Application created successfully');

                    // Redirect to the application page
                    return redirect()->route('project.application.configuration', [
                        'project_uuid' => $project->uuid,
                        'environment_uuid' => $environment->uuid,
                        'application_uuid' => $application->uuid,
                    ]);
                } catch (\Throwable $e) {
                    DB::rollBack();
                    throw $e;
                }
            } else {
                $application = Application::create([
                    'name' => generate_application_name($this->selected_repository_owner.'/'.$this->selected_repository_repo, $this->selected_branch_name),
                    'repository_project_id' => $this->selected_repository_id,
                    'git_repository' => "{$this->selected_repository_owner}/{$this->selected_repository_repo}",
                    'git_branch' => $this->selected_branch_name,
                    'build_pack' => $this->build_pack,
                    'ports_exposes' => $this->port,
                    'base_directory' => $this->base_directory,
                    'publish_directory' => $this->publish_directory,
                    'environment_id' => $environment->id,
                    'destination_id' => $destination->id,
                    'destination_type' => $destination_class,
                    'source_id' => $this->github_app->id,
                    'source_type' => $this->github_app->getMorphClass(),
                ]);
                $application->settings->is_static = $this->is_static;
                $application->settings->save();

                if ($this->build_pack === 'dockerfile' || $this->build_pack === 'dockerimage') {
                    $application->health_check_enabled = false;
                }
                if ($this->build_pack === 'dockercompose') {
                    $application['docker_compose_location'] = $this->docker_compose_location;
                    $application['base_directory'] = $this->base_directory;
                }
                $fqdn = generateFqdn($destination->server, $application->uuid);
                $application->fqdn = $fqdn;

                $application->name = generate_application_name($this->selected_repository_owner.'/'.$this->selected_repository_repo, $this->selected_branch_name, $application->uuid);
                $application->save();

                return redirect()->route('project.application.configuration', [
                    'application_uuid' => $application->uuid,
                    'environment_uuid' => $environment->uuid,
                    'project_uuid' => $project->uuid,
                ]);
            }
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
