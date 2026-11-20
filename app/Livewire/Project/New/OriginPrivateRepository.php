<?php

namespace App\Livewire\Project\New;

use App\Models\Application;
use App\Models\OriginApp;
use App\Models\Project;
use App\Rules\ValidGitBranch;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Route;
use Livewire\Component;

class OriginPrivateRepository extends Component
{
    use AuthorizesRequests;

    public $current_step = 'origin_apps';

    public $origin_apps;

    public ?int $origin_app_id = null;

    public $parameters;

    public $currentRoute;

    public $query;

    public $type;

    public string $selected_repository_full_name = '';

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

    public $build_pack = 'railpack';

    public bool $show_is_static = true;

    /**
     * Re-load the selected Origin App through the team scope on every use; the id comes from the client.
     */
    private function getOriginApp(): OriginApp
    {
        return OriginApp::connected()->where('id', $this->origin_app_id)->firstOrFail();
    }

    public function mount()
    {
        $this->currentRoute = Route::currentRouteName();
        $this->parameters = get_route_parameters();
        $this->query = request()->query();
        $this->repositories = $this->branches = collect();
        $this->origin_apps = OriginApp::connected()->select(['id', 'name', 'html_url', 'namespace_slug', 'team_id', 'is_system_wide'])->get();
    }

    public function updatedSelectedRepositoryFullName(): void
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

    public function loadRepositories($origin_app_id)
    {
        $this->repositories = collect();
        $this->branches = collect();
        $this->total_branches_count = 0;
        $this->origin_app_id = (int) $origin_app_id;
        $origin_app = $this->getOriginApp();

        try {
            $this->repositories = collect(loadOriginRepositories($origin_app))->sortBy('full_name')->values();
            $this->total_repositories_count = $this->repositories->count();

            if ($this->repositories->isNotEmpty()) {
                $this->selected_repository_full_name = data_get($this->repositories->first(), 'full_name');
            }
            $this->current_step = 'repository';
        } catch (\Throwable $e) {
            return $this->dispatch('error', $e->getMessage());
        }
    }

    public function loadBranches()
    {
        $repo = $this->repositories->firstWhere('full_name', $this->selected_repository_full_name);
        $this->branches = collect();

        try {
            if (! $repo) {
                throw new \RuntimeException('Select a repository first.');
            }
            $origin_app = $this->getOriginApp();

            $this->branches = sortBranchesByPriority(collect(loadOriginBranches($origin_app, $repo['full_name'])));
            $this->total_branches_count = $this->branches->count();

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
                'selected_repository_full_name' => $this->selected_repository_full_name,
                'selected_branch_name' => $this->selected_branch_name,
                'docker_compose_location' => $this->docker_compose_location,
            ], [
                'selected_repository_full_name' => ['required', 'string', 'regex:/^[A-Za-z0-9._-]+\/[A-Za-z0-9._-]+$/'],
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

            $origin_app = $this->getOriginApp();

            $application = Application::create([
                'name' => generate_application_name($this->selected_repository_full_name, $this->selected_branch_name),
                'repository_project_id' => null,
                'git_repository' => $this->selected_repository_full_name,
                'git_branch' => str($this->selected_branch_name)->trim()->toString(),
                'build_pack' => $this->build_pack,
                'ports_exposes' => $this->port,
                'publish_directory' => $this->publish_directory,
                'base_directory' => $this->base_directory,
                'environment_id' => $environment->id,
                'destination_id' => $destination->id,
                'destination_type' => $destination_class,
                'source_id' => $origin_app->id,
                'source_type' => $origin_app->getMorphClass(),
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
            $application->name = generate_application_name($this->selected_repository_full_name, $this->selected_branch_name, $application->uuid);
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
