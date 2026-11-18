<?php

namespace App\Livewire\Project\Application;

use App\Models\Application;
use App\Models\GiteaApp;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\PrivateKey;
use App\Rules\ValidGitBranch;
use App\Rules\ValidGitRepositoryUrl;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Gate;
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

    #[Validate(['nullable', 'string', 'max:255', 'regex:/^[^:\r\n]*$/'])]
    public ?string $gitHttpsUsername = null;

    /**
     * Write-only: the saved password or token is never sent to the browser.
     */
    #[Validate(['nullable', 'string', 'max:2048', 'regex:/^[^\r\n]*$/'])]
    public ?string $gitHttpsPassword = null;

    #[Locked]
    public bool $hasGitHttpsPassword = false;

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
            if ($this->gitRepository !== $this->application->getOriginal('git_repository')) {
                $this->validate(['gitRepository' => ['required', 'string', new ValidGitRepositoryUrl(allowRepositoryPath: true)]]);
            }
            $attributes = [
                'git_repository' => $this->gitRepository,
                'git_branch' => $this->gitBranch,
                'git_commit_sha' => $this->gitCommitSha,
                'private_key_id' => $this->privateKeyId,
            ];
            if (gitKnownHostsLines($this->gitSshKnownHosts) !== gitKnownHostsLines($this->loadedGitSshKnownHosts)) {
                $attributes['git_ssh_known_hosts'] = implode("\n", gitKnownHostsLines($this->gitSshKnownHosts)) ?: null;
            }
            $this->application->fill($attributes);
            if (filled($this->gitHttpsPassword)) {
                $this->application->git_https_username = $this->gitHttpsUsername;
                $this->application->git_https_password = $this->gitHttpsPassword;
            } elseif ($this->hasGitHttpsPassword) {
                $this->application->git_https_username = $this->gitHttpsUsername;
            }
            $this->application->save();
            // Refresh to get the trimmed values from the model
            $this->application->refresh();
            $this->syncData(false);
        } else {
            $this->gitRepository = $this->application->git_repository;
            $this->gitBranch = $this->application->git_branch;
            $this->gitCommitSha = $this->application->git_commit_sha;
            $this->privateKeyId = $this->application->private_key_id;
            $this->privateKeyName = data_get($this->application, 'private_key.name');
            $this->gitHttpsUsername = $this->application->git_https_username;
            $this->gitHttpsPassword = null;
            $this->hasGitHttpsPassword = filled($this->application->git_https_password);
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
            fn (string $line) => ['id' => sha1($line), 'published' => false, ...gitKnownHostFingerprint($line)],
            gitKnownHostsLines($this->application->git_ssh_known_hosts),
        );
    }

    /**
     * The keys that deployments use for a repository on GitHub, GitLab.com, or Bitbucket Cloud (gitProviderHostKeys()).
     */
    public function publishedHostKeyFingerprints(): array
    {
        $host = strtolower($this->application->gitSshHost()['host'] ?? '');
        if (! in_array($host, gitPinnedHosts(), true)) {
            return [];
        }
        $lines = array_filter(gitProviderHostKeys(), fn (string $line) => in_array($host, gitKnownHostsLineHosts($line), true));

        return array_map(
            fn (string $line) => ['id' => 'published-'.sha1($line), 'published' => true, ...gitKnownHostFingerprint($line), 'host' => $host],
            array_values($lines),
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
            $hosts = $this->application->gitSshHostsToScan();
            if ($sshHost === null && $hosts === []) {
                $this->dispatch('error', 'The repository does not use SSH.');

                return;
            }
            $providerHosts = $sshHost === null ? null : gitProviderHostKeySource($sshHost['host']);
            if ($providerHosts !== null) {
                $count = count(fetchGitProviderHostKeys($providerHosts));
                $this->dispatch('success', "Deployments now use the {$count} host keys that {$sshHost['host']} publishes.");
            }
            if ($hosts === []) {
                return;
            }
            $fetchedHostKeys = $this->application->fetchGitSshHostKeys();
            // A gitlab.com key that is the same as a built-in key needs no accept.
            $builtInHostKeys = collect(gitProviderHostKeys())->flatMap(function (string $line) {
                [$hosts, $key] = explode(' ', $line, 2);

                return array_map(fn (string $host) => "{$host} {$key}", explode(',', $hosts));
            })->all();
            $this->fetchedHostKeys = array_values(array_diff($fetchedHostKeys, $builtInHostKeys));
            if ($fetchedHostKeys === []) {
                $names = implode(', ', array_map(fn (array $host) => "{$host['host']} (port {$host['port']})", $hosts));
                $this->dispatch('error', "Could not read the host keys of {$names}. To add a key, click Edit manually and paste it.");
            } elseif ($this->fetchedHostKeys === []) {
                $this->dispatch('success', 'The host sends the built-in published keys. Nothing to accept.');
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

    /**
     * Other keys and sources of the team. Users without write access on the whole team keep the ones the
     * application already uses, so they only see those.
     */
    private function canUseTeamCredentials(): bool
    {
        return Gate::allows('useTeamCredentials', $this->application);
    }

    private function getPrivateKeys()
    {
        if (! $this->canUseTeamCredentials()) {
            $this->privateKeys = collect();

            return;
        }

        $this->privateKeys = PrivateKey::where('team_id', $this->application->team()->id)->get()->reject(function ($key) {
            return $key->id == $this->privateKeyId;
        });
    }

    private function getSources()
    {
        if (! $this->canUseTeamCredentials()) {
            $this->sources = collect();

            return;
        }

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
            $this->authorize('useTeamCredentials', $this->application);
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
            $knownHostsLines = collect(preg_split('/\R/', (string) $this->gitSshKnownHosts))
                ->map(fn (string $line) => preg_replace('/\s+/', ' ', trim($line)))
                ->filter(fn (string $line) => $line !== '');
            if ($knownHostsLines->contains(fn (string $line) => ! isValidGitKnownHostsLine($line))) {
                throw ValidationException::withMessages(['gitSshKnownHosts' => 'Each line must be "host key-type key" (for example "[git.example.com]:2222 ssh-ed25519 AAAA..."). Hashed hosts and comments are not supported.']);
            }
            if (filled($this->gitHttpsUsername) && blank($this->gitHttpsPassword) && ! $this->hasGitHttpsPassword) {
                throw ValidationException::withMessages(['gitHttpsPassword' => 'Enter the password or token for this username.']);
            }
            $loadedKnownHostsLines = array_map(fn (string $line) => preg_replace('/\s+/', ' ', trim($line)), preg_split('/\R/', (string) $this->loadedGitSshKnownHosts));
            if ($knownHostsLines->contains(fn (string $line) => gitKnownHostsLineHasHostWithPublishedKeys($line) && ! in_array($line, $loadedKnownHostsLines, true))) {
                throw ValidationException::withMessages(['gitSshKnownHosts' => 'Coolify always uses the published host keys of '.implode(', ', gitHostsWithPublishedKeys()).'. Remove the lines of these hosts.']);
            }
            $this->syncData(true);
            $this->dispatch('success', 'Application source updated!');
            $this->dispatch('configurationChanged');
        } catch (ValidationException $e) {
            if (array_key_exists('gitSshKnownHosts', $e->errors())) {
                $this->dispatch('known-hosts-invalid');
            }

            if (array_key_exists('gitSshKnownHosts', $e->errors())) {
                throw $e;
            }

            handleError($e, $this);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function removeGitHttpsCredential()
    {
        try {
            $this->authorize('update', $this->application);
            $this->application->forceFill([
                'git_https_username' => null,
                'git_https_password' => null,
            ])->save();
            $this->syncData();
            $this->dispatch('success', 'HTTPS credentials removed.');
            $this->dispatch('configurationChanged');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function changeSource($sourceId, $sourceType)
    {
        try {
            $this->authorize('update', $this->application);
            $this->authorize('useTeamCredentials', $this->application);
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
