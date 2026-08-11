<?php

namespace App\Livewire\Project\New;

use App\Models\Application;
use App\Models\GitlabApp;
use App\Models\Project;
use App\Rules\ValidGitBranch;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Route;
use Livewire\Component;

class GitlabPrivateRepository extends Component
{
    use AuthorizesRequests;

    public $current_step = 'gitlab_apps';

    public $gitlab_apps;

    public ?int $gitlab_app_id = null;

    public $parameters;

    public $currentRoute;

    public $query;

    public $type;

    public int $selected_project_id;

    public int $selected_gitlab_app_id;

    public string $selected_repository_path = '';

    public string $selected_branch_name = 'main';

    public $repositories;

    public int $total_repositories_count = 0;

    public $branches;

    public int $total_branches_count = 0;

    public int $port = 3000;

    public bool $is_static = false;

    public ?string $publish_directory = null;

    public ?string $base_directory = '/';

    public ?string $docker_compose_location = '/docker-compose.yaml';

    protected int $page = 1;

    public $build_pack = 'railpack';

    public bool $show_is_static = true;

    private function getGitlabApp(): GitlabApp
    {
        return GitlabApp::private()->where('id', $this->gitlab_app_id)->firstOrFail();
    }

    public function mount()
    {
        $this->currentRoute = Route::currentRouteName();
        $this->parameters = get_route_parameters();
        $this->query = request()->query();
        $this->repositories = $this->branches = collect();
        $this->gitlab_apps = GitlabApp::private()->select(['id', 'name', 'html_url', 'team_id', 'is_system_wide', 'is_public'])->get();
    }

    public function updatedSelectedProjectId(): void
    {
        $this->loadBranches();
    }

    public function updatedBuildPack()
    {
        if ($this->build_pack === 'nixpacks' || $this->build_pack === 'railpack') {
            $this->show_is_static = true;
            if (! $this->is_static) {
                $this->port = 3000;
            }
        } elseif ($this->build_pack === 'static') {
            $this->show_is_static = false;
            $this->is_static = false;
            $this->port = 80;
        } else {
            $this->show_is_static = false;
            $this->is_static = false;
        }
    }

    public function loadRepositories($gitlab_app_id)
    {
        $this->repositories = collect();
        $this->branches = collect();
        $this->total_branches_count = 0;
        $this->page = 1;
        $this->selected_gitlab_app_id = $gitlab_app_id;
        $this->gitlab_app_id = $gitlab_app_id;
        $gitlab_app = $this->getGitlabApp();

        try {
            $result = loadGitlabRepositories($gitlab_app, $this->page);
            $this->repositories = $this->repositories->concat(collect($result['repositories']));

            while ($result['has_more'] && $this->page < 50) {
                $this->page++;
                $result = loadGitlabRepositories($gitlab_app, $this->page);
                $this->repositories = $this->repositories->concat(collect($result['repositories']));
            }
            $this->total_repositories_count = $this->repositories->count();

            $this->repositories = $this->repositories->sortBy('name');
            if ($this->repositories->count() > 0) {
                $first = $this->repositories->first();
                $this->selected_project_id = data_get($first, 'id');
                $this->selected_repository_path = data_get($first, 'path_with_namespace');
            }
            $this->current_step = 'repository';
        } catch (\Throwable $e) {
            return $this->dispatch('error', $e->getMessage());
        }
    }

    public function loadBranches()
    {
        $repo = $this->repositories->where('id', $this->selected_project_id)->first();
        $this->selected_repository_path = data_get($repo, 'path_with_namespace', $this->selected_repository_path);
        $this->branches = collect();
        $this->page = 1;
        $gitlab_app = $this->getGitlabApp();

        try {
            $branches = loadGitlabBranches($gitlab_app, $this->selected_project_id, $this->page);
            $this->total_branches_count = count($branches);
            $this->branches = $this->branches->concat(collect($branches));

            while ($this->total_branches_count === 100) {
                $this->page++;
                $branches = loadGitlabBranches($gitlab_app, $this->selected_project_id, $this->page);
                $this->total_branches_count = count($branches);
                $this->branches = $this->branches->concat(collect($branches));
            }

            $this->branches = sortBranchesByPriority($this->branches);
            $defaultBranch = data_get($repo, 'default_branch', 'main');
            $this->selected_branch_name = $this->branches->contains('name', $defaultBranch) ? $defaultBranch : data_get($this->branches, '0.name', 'main');
        } catch (\Throwable $e) {
            return $this->dispatch('error', $e->getMessage());
        }
    }

    public function submit()
    {
        try {
            $this->authorize('create', Application::class);

            $validator = validator([
                'selected_repository_path' => $this->selected_repository_path,
                'selected_branch_name' => $this->selected_branch_name,
                'docker_compose_location' => $this->docker_compose_location,
            ], [
                'selected_repository_path' => 'required|string',
                'selected_branch_name' => ['required', 'string', new ValidGitBranch],
                'docker_compose_location' => ValidationPatterns::filePathRules(),
            ]);

            if ($validator->fails()) {
                throw new \RuntimeException('Invalid repository data: '.$validator->errors()->first());
            }

            $destination_uuid = $this->query['destination'] ?? null;
            $destination = find_destination_for_current_team($destination_uuid);
            if (! $destination) {
                throw new \Exception('Destination not found.');
            }
            $destination_class = $destination->getMorphClass();

            $project = Project::ownedByCurrentTeam()->where('uuid', $this->parameters['project_uuid'])->firstOrFail();
            $environment = $project->environments()->where('uuid', $this->parameters['environment_uuid'])->firstOrFail();

            $gitlab_app = $this->getGitlabApp();

            $application = Application::create([
                'name' => generate_application_name($this->selected_repository_path, $this->selected_branch_name),
                'repository_project_id' => $this->selected_project_id,
                'git_repository' => $this->selected_repository_path,
                'git_branch' => str($this->selected_branch_name)->trim()->toString(),
                'build_pack' => $this->build_pack,
                'ports_exposes' => $this->port,
                'publish_directory' => $this->publish_directory,
                'base_directory' => $this->base_directory,
                'environment_id' => $environment->id,
                'destination_id' => $destination->id,
                'destination_type' => $destination_class,
                'source_id' => $gitlab_app->id,
                'source_type' => $gitlab_app->getMorphClass(),
            ]);
            $application->settings->is_static = $this->is_static;
            $application->settings->save();

            if ($this->build_pack === 'dockerfile' || $this->build_pack === 'dockerimage') {
                $application->health_check_enabled = false;
            }
            if ($this->build_pack === 'dockercompose') {
                $application['docker_compose_location'] = $this->docker_compose_location;
            }
            $fqdn = generateUrl(server: $destination->server, random: $application->uuid);
            $application->fqdn = $fqdn;
            $application->name = generate_application_name($this->selected_repository_path, $this->selected_branch_name, $application->uuid);
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
