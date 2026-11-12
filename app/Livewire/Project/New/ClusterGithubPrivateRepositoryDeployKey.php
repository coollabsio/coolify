<?php

namespace App\Livewire\Project\New;

use App\Actions\Node\Build\IssueNodeGitCredential;
use App\Models\PrivateKey;
use App\Rules\ValidGitBranch;
use App\Rules\ValidGitRepositoryUrl;
use App\Traits\CreatesClusterGitApplication;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** The v4 "Private Repository (with Deploy Key)" page for a cluster target. */
class ClusterGithubPrivateRepositoryDeployKey extends Component
{
    use AuthorizesRequests, CreatesClusterGitApplication;

    public string $current_step = 'private_keys';

    public Collection $private_keys;

    #[Locked]
    public ?int $private_key_id = null;

    public string $repository_url = '';

    public string $branch = 'main';

    public function mount(): void
    {
        $this->mountClusterTarget();
        $this->private_keys = Gate::allows('useTeamCredentials')
            ? IssueNodeGitCredential::privateKeys($this->clusterProject()->team_id)->orderBy('name')->get()
            : collect();
    }

    /** The selected key, checked again against the project team on every request. */
    private function privateKey(): PrivateKey
    {
        return IssueNodeGitCredential::privateKeys($this->clusterProject()->team_id)->findOrFail($this->private_key_id);
    }

    public function setPrivateKey(int $private_key_id): void
    {
        $this->authorizeClusterCredentials();
        $this->private_key_id = $private_key_id;
        $this->privateKey();
        $this->current_step = 'repository';
    }

    public function submit()
    {
        $this->repository_url = trim($this->repository_url);
        $this->branch = trim($this->branch);
        $this->validate([
            'repository_url' => ['required', 'string', 'max:2048', new ValidGitRepositoryUrl],
            'branch' => ['required', 'string', 'max:255', new ValidGitBranch],
            ...$this->clusterBuildRules(),
        ], [], ['repository_url' => 'Repository', 'branch' => 'Branch']);

        return $this->createClusterGitApplication(self::sshUrl($this->repository_url), $this->branch, null, $this->privateKey(), 'repository_url');
    }

    /** Like v4, `https://host/owner/repo` becomes `git@host:owner/repo.git`; SSH URLs stay as they are. */
    private static function sshUrl(string $url): string
    {
        if (str_starts_with($url, 'ssh://') || parseScpStyleGitUrl($url) !== null) {
            return $url;
        }
        $host = (string) parse_url($url, PHP_URL_HOST);
        $segments = array_slice(explode('/', trim((string) parse_url($url, PHP_URL_PATH), '/')), 0, 2);

        return str("git@{$host}:".implode('/', $segments))->finish('.git')->toString();
    }

    public function render()
    {
        return view('livewire.project.new.cluster-github-private-repository-deploy-key');
    }
}
