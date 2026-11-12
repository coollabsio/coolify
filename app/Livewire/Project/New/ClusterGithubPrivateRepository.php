<?php

namespace App\Livewire\Project\New;

use App\Actions\Node\Build\IssueNodeGitCredential;
use App\Models\GithubApp;
use App\Rules\ValidGitBranch;
use App\Traits\CreatesClusterGitApplication;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;
use Throwable;

/** The v4 "Private Repository (with GitHub App)" page for a cluster target. */
class ClusterGithubPrivateRepository extends Component
{
    use AuthorizesRequests, CreatesClusterGitApplication;

    public string $current_step = 'github_apps';

    public Collection $github_apps;

    #[Locked]
    public ?int $selected_github_app_id = null;

    #[Locked]
    public string $github_app_name = '';

    #[Locked]
    public string $github_app_installation_path = '';

    public ?int $selected_repository_id = null;

    public string $selected_repository_owner = '';

    public string $selected_repository_repo = '';

    public string $selected_branch_name = 'main';

    public Collection $repositories;

    public Collection $branches;

    public function mount(): void
    {
        $this->mountClusterTarget();
        $this->repositories = $this->branches = collect();
        $this->github_apps = IssueNodeGitCredential::githubApps($this->clusterProject()->team_id)->orderBy('name')->get();
    }

    public function updatedSelectedRepositoryId(): void
    {
        $this->loadBranches();
    }

    /** The selected App, checked again against the project team on every request. */
    private function githubApp(): GithubApp
    {
        return IssueNodeGitCredential::githubApps($this->clusterProject()->team_id)->findOrFail($this->selected_github_app_id);
    }

    public function loadRepositories(int $github_app_id): void
    {
        $this->authorizeClusterCredentials();
        $this->repositories = $this->branches = collect();
        $this->selected_github_app_id = $github_app_id;
        $githubApp = $this->githubApp();
        $this->github_app_name = $githubApp->name;
        $this->github_app_installation_path = getInstallationPath($githubApp);
        try {
            $token = generateGithubInstallationToken($githubApp);
            $page = 1;
            do {
                $result = loadRepositoryByPage($githubApp, $token, $page++);
                $this->repositories = $this->repositories->concat($result['repositories']);
            } while ($this->repositories->count() < $result['total_count'] && $result['repositories'] !== []);
        } catch (Throwable $e) {
            $this->repositories = collect();
            handleError($e, $this);

            return;
        }
        $this->repositories = $this->repositories->sortBy('name')->values();
        $this->selected_repository_id = data_get($this->repositories->first(), 'id');
        $this->current_step = 'repository';
    }

    public function loadBranches(): void
    {
        $this->authorizeClusterCredentials();
        $repository = $this->repositories->firstWhere('id', $this->selected_repository_id);
        $this->selected_repository_owner = (string) data_get($repository, 'owner.login');
        $this->selected_repository_repo = (string) data_get($repository, 'name');
        $this->branches = collect();
        try {
            $githubApp = $this->githubApp();
            $token = generateGithubInstallationToken($githubApp);
            $page = 1;
            do {
                $response = Http::GitHub($githubApp->api_url, $token)
                    ->timeout(20)
                    ->retry(3, 200, throw: false)
                    ->get('/repos/'.encodeGithubPathSegment($this->selected_repository_owner).'/'.encodeGithubPathSegment($this->selected_repository_repo).'/branches', [
                        'per_page' => 100,
                        'page' => $page++,
                    ]);
                if ($response->status() !== 200) {
                    throw new RuntimeException("Failed to load branches of {$this->selected_repository_owner}/{$this->selected_repository_repo} with error: ".data_get($response->json(), 'message', "HTTP {$response->status()}"));
                }
                $this->branches = $this->branches->concat($response->json());
            } while (count($response->json()) === 100);
        } catch (Throwable $e) {
            $this->branches = collect();
            handleError($e, $this);

            return;
        }
        $this->branches = sortBranchesByPriority($this->branches);
        $defaultBranch = data_get($repository, 'default_branch', 'main');
        $this->selected_branch_name = $this->branches->contains('name', $defaultBranch)
            ? $defaultBranch
            : data_get($this->branches, '0.name', 'main');
    }

    public function submit()
    {
        $this->validate([
            'selected_repository_owner' => ['required', 'string', 'regex:/^[a-zA-Z0-9\-_]+$/'],
            'selected_repository_repo' => ['required', 'string', 'regex:/^[a-zA-Z0-9\-_\.]+$/'],
            'selected_branch_name' => ['required', 'string', 'max:255', new ValidGitBranch],
            ...$this->clusterBuildRules(),
        ]);
        $githubApp = $this->githubApp();
        $repository = rtrim($githubApp->html_url, '/').'/'.$this->selected_repository_owner.'/'.$this->selected_repository_repo;

        return $this->createClusterGitApplication($repository, $this->selected_branch_name, $githubApp, null, 'selected_branch_name');
    }

    public function render()
    {
        return view('livewire.project.new.cluster-github-private-repository');
    }
}
