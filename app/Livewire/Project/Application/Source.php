<?php

namespace App\Livewire\Project\Application;

use App\Models\Application;
use App\Models\GiteaApp;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\PrivateKey;
use App\Rules\ValidGitBranch;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Source extends Component
{
    use AuthorizesRequests;

    public Application $application;

    #[Locked]
    public $privateKeys;

    #[Validate(['nullable', 'string'])]
    public ?string $privateKeyName = null;

    #[Locked]
    public ?int $privateKeyId = null;

    #[Validate(['required', 'string'])]
    public string $gitRepository;

    #[Validate(['required', 'string', new ValidGitBranch])]
    public string $gitBranch;

    #[Validate(['nullable', 'string', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9._\-\/]*$/'])]
    public ?string $gitCommitSha = null;

    #[Locked]
    public $sources;

    #[Validate(['nullable', 'string', 'max:65535'])]
    public ?string $gitSshKnownHosts = null;

    /**
     * The saved host keys when the form was loaded. Save writes the host keys only when the user changed
     * them, so it does not remove keys that a deployment saved after the page opened.
     */
    #[Locked]
    public ?string $loadedGitSshKnownHosts = null;

    /**
     * Host keys that the Git host sends now, which the user can accept or decline.
     *
     * @var list<string>
     */
    #[Locked]
    public array $fetchedHostKeys = [];

    public function mount()
    {
        try {
            $this->syncData();
            $this->getPrivateKeys();
            $this->getSources();
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function updatedGitRepository()
    {
        $this->gitRepository = trim($this->gitRepository);
    }

    public function updatedGitBranch()
    {
        $this->gitBranch = trim($this->gitBranch);
    }

    public function updatedGitCommitSha()
    {
        $this->gitCommitSha = trim($this->gitCommitSha);
    }

    private function syncData(bool $toModel = false): void
    {
        if ($toModel) {
            $this->validate();
            $attributes = [
                'git_repository' => $this->gitRepository,
                'git_branch' => $this->gitBranch,
                'git_commit_sha' => $this->gitCommitSha,
                'private_key_id' => $this->privateKeyId,
            ];
            if (gitKnownHostsLines($this->gitSshKnownHosts) !== gitKnownHostsLines($this->loadedGitSshKnownHosts)) {
                $attributes['git_ssh_known_hosts'] = implode("\n", gitKnownHostsLines($this->gitSshKnownHosts)) ?: null;
            }
            $this->application->update($attributes);
            // Refresh to get the trimmed values from the model
            $this->application->refresh();
            $this->syncData(false);
        } else {
            $this->gitRepository = $this->application->git_repository;
            $this->gitBranch = $this->application->git_branch;
            $this->gitCommitSha = $this->application->git_commit_sha;
            $this->privateKeyId = $this->application->private_key_id;
            $this->privateKeyName = data_get($this->application, 'private_key.name');
            $this->syncKnownHostsFromModel();
        }
    }

    private function syncKnownHostsFromModel(): void
    {
        $this->gitSshKnownHosts = $this->application->git_ssh_known_hosts;
        $this->loadedGitSshKnownHosts = $this->application->git_ssh_known_hosts;
    }

    /**
     * The SHA256 fingerprints of the saved host keys, in the format that `ssh-keygen -lf` shows.
     * The id identifies the line, because two hosts can have the same key.
     *
     * @return list<array{id: string, host: string, type: string, fingerprint: string}>
     */
    public function knownHostFingerprints(): array
    {
        return array_map(
            fn (string $line) => ['id' => sha1($line), ...gitKnownHostFingerprint($line)],
            gitKnownHostsLines($this->application->git_ssh_known_hosts),
        );
    }

    /**
     * Removes the saved host key line with this id. The next deployment saves the key that the host sends then.
     */
    public function removeHostKey(string $id): void
    {
        try {
            $this->authorize('update', $this->application);
            $lines = array_filter(
                gitKnownHostsLines($this->application->git_ssh_known_hosts),
                fn (string $line) => sha1($line) !== $id,
            );
            $this->application->update(['git_ssh_known_hosts' => implode("\n", $lines) ?: null]);
            $this->syncKnownHostsFromModel();
            $this->dispatch('success', 'Host key removed.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    /**
     * @return list<array{host: string, type: string, fingerprint: string}>
     */
    public function fetchedHostKeyFingerprints(): array
    {
        return array_map(gitKnownHostFingerprint(...), $this->fetchedHostKeys);
    }

    public function fetchHostKeys(): void
    {
        try {
            $this->authorize('update', $this->application);
            $this->fetchedHostKeys = [];
            $sshHost = $this->application->gitSshHost();
            if ($sshHost === null) {
                $this->dispatch('error', 'The repository does not use SSH.');

                return;
            }
            if (in_array(strtolower($sshHost['host']), gitPinnedHosts(), true)) {
                $this->dispatch('info', "Coolify always uses the published host keys of {$sshHost['host']}.");

                return;
            }
            $this->fetchedHostKeys = $this->application->fetchGitSshHostKeys();
            if ($this->fetchedHostKeys === []) {
                $this->dispatch('error', "Could not read the host keys of {$sshHost['host']} on port {$sshHost['port']}.");
            }
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    /**
     * Replaces the saved keys of the fetched host with the fetched keys.
     */
    public function acceptFetchedHostKeys(): void
    {
        try {
            $this->authorize('update', $this->application);
            $lines = gitKnownHostsReplaceHostKeys($this->application->git_ssh_known_hosts, $this->fetchedHostKeys);
            $this->application->update(['git_ssh_known_hosts' => implode("\n", $lines) ?: null]);
            $this->fetchedHostKeys = [];
            $this->syncKnownHostsFromModel();
            $this->dispatch('success', 'Host keys saved. Later deployments accept only these keys.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function declineFetchedHostKeys(): void
    {
        $this->fetchedHostKeys = [];
    }

    private function getPrivateKeys()
    {
        $this->privateKeys = PrivateKey::where('team_id', $this->application->team()->id)->get()->reject(function ($key) {
            return $key->id == $this->privateKeyId;
        });
    }

    private function getSources()
    {
        $this->sources = $this->application->team()->sources()->filter(function ($source) {
            if ($source->id === $this->application->source_id
                && $source->getMorphClass() === $this->application->source_type) {
                return false;
            }
            if ($source instanceof GithubApp) {
                return ! is_null($source->app_id);
            }
            if ($source instanceof GitlabApp || $source instanceof GiteaApp) {
                return $source->isConnected();
            }

            return true;
        })->sortBy('name');
    }

    public function setPrivateKey(int $privateKeyId)
    {
        try {
            $this->authorize('update', $this->application);
            $key = PrivateKey::where('team_id', $this->application->team()->id)->findOrFail($privateKeyId);
            $this->privateKeyId = $key->id;
            $this->syncData(true);
            $this->getPrivateKeys();
            $this->application->refresh();
            $this->privateKeyName = $this->application->private_key->name;
            $this->dispatch('success', 'Private key updated!');
            $this->dispatch('configurationChanged');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function submit()
    {

        try {
            $this->authorize('update', $this->application);
            if (str($this->gitCommitSha)->isEmpty()) {
                $this->gitCommitSha = 'HEAD';
            }
            $invalidKnownHostsLine = collect(preg_split('/\R/', (string) $this->gitSshKnownHosts))
                ->map(fn (string $line) => preg_replace('/\s+/', ' ', trim($line)))
                ->first(fn (string $line) => $line !== '' && ! isValidGitKnownHostsLine($line));
            if ($invalidKnownHostsLine !== null) {
                throw ValidationException::withMessages(['gitSshKnownHosts' => 'Each line must be "host key-type key" (for example "[git.example.com]:2222 ssh-ed25519 AAAA..."). Hashed hosts and comments are not supported.']);
            }
            $this->syncData(true);
            $this->dispatch('success', 'Application source updated!');
            $this->dispatch('configurationChanged');
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function changeSource($sourceId, $sourceType)
    {
        try {
            $this->authorize('update', $this->application);
            $allowedSourceTypes = [GithubApp::class, GitlabApp::class, GiteaApp::class];
            abort_unless(in_array($sourceType, $allowedSourceTypes, true), 404);
            $teamId = $this->application->team()->id;
            $source = $sourceType::query()
                ->where(fn ($query) => $query->where('team_id', $teamId)->orWhere('is_system_wide', true))
                ->findOrFail($sourceId);
            $this->application->update([
                'source_id' => $source->id,
                'source_type' => $sourceType,
            ]);
            $this->dispatch('configurationChanged');

            ['repository' => $customRepository] = $this->application->customRepository();
            $repositorySlug = gitRepositorySlug($customRepository);
            $repository_project_id = null;

            if ($sourceType === GithubApp::class) {
                $repository = githubApi($source, "repos/{$repositorySlug}");
                $repository_project_id = data_get($repository, 'data.id');
            } elseif ($sourceType === GitlabApp::class) {
                if ($source->isConnected()) {
                    $encoded = urlencode($repositorySlug);
                    $project = gitlabApi($source, "/projects/{$encoded}");
                    $repository_project_id = data_get($project, 'data.id');
                }
            } elseif ($sourceType === GiteaApp::class) {
                if ($source->isConnected()) {
                    $repositoryPath = collect(explode('/', $repositorySlug, 2))->map(fn ($part) => rawurlencode($part))->implode('/');
                    $repository = giteaApi($source, "/repos/{$repositoryPath}");
                    $repository_project_id = data_get($repository, 'data.id');
                }
            }

            if (isset($repository_project_id) && $this->application->repository_project_id !== $repository_project_id) {
                $this->application->repository_project_id = $repository_project_id;
                $this->application->save();
            }

            $this->application->refresh();
            $this->getSources();
            $this->dispatch('success', 'Source updated!');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
