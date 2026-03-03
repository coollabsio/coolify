<?php

namespace App\Livewire\Server;

use App\Enums\GithubRunnerStatus;
use App\Models\GithubApp;
use App\Models\GithubRunnerConfig;
use App\Models\GithubRunnerExecution;
use App\Models\Server;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Http;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class GithubRunners extends Component
{
    use AuthorizesRequests;

    public Server $server;

    public array $parameters = [];

    public ?int $selectedGithubAppId = null;

    #[Validate(['nullable', 'string', 'max:255'])]
    public ?string $runnerGroupName = null;

    #[Validate(['required', 'string', 'min:1'])]
    public string $labels = 'self-hosted,coolify';

    #[Validate(['required', 'integer', 'min:1', 'max:32'])]
    public int $maxRunners = 4;

    #[Validate(['required', 'integer', 'min:1', 'max:1440'])]
    public int $capacityWaitTimeout = 60;

    #[Validate(['required', 'string', 'min:1'])]
    public string $runnerUser = 'runner';

    #[Validate(['required', 'string', 'min:1'])]
    public string $runnerBaseDir = '/opt/github-runners';

    public ?string $runnerVersion = null;

    #[Validate('boolean')]
    public bool $isEnabled = true;

    public array $accessibleRepositories = [];

    public ?string $repositoryError = null;

    public bool $repositoriesLoaded = false;

    public bool $repositoriesLoading = false;

    public bool $skipNextSelectedAppReload = false;

    public ?string $originalRunnerGroupName = null;

    public function mount(string $server_uuid): void
    {
        try {
            $this->server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
            $this->parameters = get_route_parameters();
            $this->loadConfig();
        } catch (\Throwable) {
            $this->redirectRoute('server.index');
        }
    }

    #[Computed]
    public function githubApps()
    {
        return GithubApp::ownedByCurrentTeam()
            ->whereNotNull('app_id')
            ->whereNotNull('organization')
            ->where('organization', '!=', '')
            ->get();
    }

    #[Computed]
    public function config(): ?GithubRunnerConfig
    {
        return $this->server->githubRunnerConfig;
    }

    #[Computed]
    public function activeRunnerCount(): int
    {
        return $this->config?->activeRunnerCount() ?? 0;
    }

    #[Computed]
    public function selectedApp(): ?GithubApp
    {
        if (! $this->selectedGithubAppId) {
            return null;
        }

        return GithubApp::find($this->selectedGithubAppId);
    }

    #[Computed]
    public function selectedAppHasRunnerPermission(): ?bool
    {
        return $this->selectedApp?->organization_self_hosted_runners === 'write';
    }

    public function loadConfig(): void
    {
        $config = $this->server->githubRunnerConfig;
        if ($config) {
            $this->selectedGithubAppId = $config->github_app_id;
            $this->runnerGroupName = $config->githubApp?->runner_group_name;
            $this->originalRunnerGroupName = $this->normalizeRunnerGroupName($this->runnerGroupName);
            $this->skipNextSelectedAppReload = true;
            $this->labels = implode(',', $config->labels ?? []);
            $this->maxRunners = $config->max_runners;
            $this->capacityWaitTimeout = $config->capacity_wait_timeout;
            $this->runnerUser = $config->runner_user;
            $this->runnerBaseDir = $config->runner_base_dir;
            $this->runnerVersion = $config->runner_version;
            $this->isEnabled = $config->is_enabled;
        }
    }

    public function initializeRepositories(): void
    {
        if ($this->repositoriesLoaded) {
            return;
        }

        $this->loadAccessibleRepositories();
    }

    public function updatedSelectedGithubAppId(): void
    {
        if ($this->skipNextSelectedAppReload) {
            $this->skipNextSelectedAppReload = false;

            return;
        }

        $this->runnerGroupName = $this->selectedApp?->runner_group_name;
        $this->originalRunnerGroupName = $this->normalizeRunnerGroupName($this->runnerGroupName);
        $this->repositoriesLoaded = true;
        $this->loadAccessibleRepositories();
    }

    public function loadAccessibleRepositories(): void
    {
        $this->repositoriesLoading = true;
        $this->repositoriesLoaded = true;
        $this->accessibleRepositories = [];
        $this->repositoryError = null;

        $app = $this->selectedApp;

        if (! $app || ! $app->installation_id) {
            $this->repositoriesLoading = false;

            return;
        }

        try {
            $token = generateGithubInstallationToken($app);
            $allRepos = [];
            $page = 1;

            do {
                $result = loadRepositoryByPage($app, $token, $page);
                $repos = data_get($result, 'repositories', []);
                $totalCount = data_get($result, 'total_count', 0);

                foreach ($repos as $repo) {
                    $allRepos[] = data_get($repo, 'full_name');
                }

                $page++;
            } while (count($allRepos) < $totalCount && count($allRepos) < 500 && count($repos) > 0);

            sort($allRepos);
            $this->accessibleRepositories = $allRepos;
        } catch (\Throwable $e) {
            $this->repositoryError = 'Could not load repositories: '.$e->getMessage();
        } finally {
            $this->repositoriesLoading = false;
        }
    }

    public function submit()
    {
        try {
            $this->authorize('update', $this->server);
            $this->validate();

            if (! $this->selectedGithubAppId) {
                throw new \Exception('Please select a GitHub App.');
            }

            $runnerGroupName = $this->normalizeRunnerGroupName($this->runnerGroupName);
            if ($runnerGroupName === null) {
                $runnerGroupName = $this->generateDefaultRunnerGroupName();
                $this->runnerGroupName = $runnerGroupName;
            }
            $runnerGroupNameIsDirty = $runnerGroupName !== $this->originalRunnerGroupName;

            if ($runnerGroupNameIsDirty) {
                GithubApp::query()
                    ->whereKey($this->selectedGithubAppId)
                    ->update(['runner_group_name' => $runnerGroupName]);
                $this->runnerGroupName = $runnerGroupName;

                $selectedGithubApp = GithubApp::query()->find($this->selectedGithubAppId);
                if ($selectedGithubApp) {
                    $this->syncRunnerGroupNameToGithub($selectedGithubApp);
                }
            }

            $this->originalRunnerGroupName = $runnerGroupName;

            $labelsArray = array_map('trim', explode(',', $this->labels));
            $labelsArray = array_values(array_filter($labelsArray));

            if (empty($labelsArray)) {
                throw new \Exception('At least one label is required.');
            }

            $config = $this->server->githubRunnerConfig;

            if ($config) {
                $config->update([
                    'github_app_id' => $this->selectedGithubAppId,
                    'labels' => $labelsArray,
                    'max_runners' => $this->maxRunners,
                    'capacity_wait_timeout' => $this->capacityWaitTimeout,
                    'runner_user' => $this->runnerUser,
                    'runner_base_dir' => $this->runnerBaseDir,
                    'runner_version' => $this->runnerVersion ?: null,
                    'is_enabled' => $this->isEnabled,
                ]);
            } else {
                GithubRunnerConfig::create([
                    'server_id' => $this->server->id,
                    'github_app_id' => $this->selectedGithubAppId,
                    'labels' => $labelsArray,
                    'max_runners' => $this->maxRunners,
                    'capacity_wait_timeout' => $this->capacityWaitTimeout,
                    'runner_user' => $this->runnerUser,
                    'runner_base_dir' => $this->runnerBaseDir,
                    'runner_version' => $this->runnerVersion ?: null,
                    'is_enabled' => $this->isEnabled,
                ]);
            }

            $this->server->refresh();
            $this->dispatch('success', 'GitHub Runner configuration saved.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    private function normalizeRunnerGroupName(?string $runnerGroupName): ?string
    {
        if (! is_string($runnerGroupName)) {
            return null;
        }

        $trimmedName = trim($runnerGroupName);

        if ($trimmedName === '') {
            return null;
        }

        return preg_replace('/\s+/', ' ', $trimmedName) ?? $trimmedName;
    }

    private function generateDefaultRunnerGroupName(): string
    {
        return 'Coolify-'.((string) new Cuid2(7));
    }

    private function syncRunnerGroupNameToGithub(GithubApp $githubApp): void
    {
        $desiredRunnerGroupName = $this->normalizeRunnerGroupName($githubApp->runner_group_name);

        if ($desiredRunnerGroupName === null) {
            return;
        }

        if (! $githubApp->installation_id || ! $githubApp->organization) {
            return;
        }

        $token = generateGithubInstallationToken($githubApp);
        $apiUrl = $githubApp->api_url ?? 'https://api.github.com';
        $headers = [
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
        ];

        if ($githubApp->runner_group_id) {
            $patchResponse = Http::withHeaders($headers)->patch(
                "{$apiUrl}/orgs/{$githubApp->organization}/actions/runner-groups/{$githubApp->runner_group_id}",
                [
                    'name' => $desiredRunnerGroupName,
                    'allows_public_repositories' => true,
                ]
            );

            if ($patchResponse->successful()) {
                return;
            }

            if ($patchResponse->status() !== 404) {
                throw new \RuntimeException(
                    'Failed to sync runner group: '.data_get($patchResponse->json(), 'message', $patchResponse->body())
                );
            }

            $githubApp->update(['runner_group_id' => null]);
            $githubApp->refresh();
        }

        $createResponse = Http::withHeaders($headers)->post(
            "{$apiUrl}/orgs/{$githubApp->organization}/actions/runner-groups",
            [
                'name' => $desiredRunnerGroupName,
                'visibility' => 'selected',
                'allows_public_repositories' => true,
            ]
        );

        if (! $createResponse->successful()) {
            throw new \RuntimeException(
                'Failed to create runner group: '.data_get($createResponse->json(), 'message', $createResponse->body())
            );
        }

        $githubApp->update([
            'runner_group_id' => (int) data_get($createResponse->json(), 'id'),
            'runner_group_name' => $desiredRunnerGroupName,
        ]);
    }

    public function toggleEnabled()
    {
        try {
            $this->authorize('update', $this->server);
            $config = $this->server->githubRunnerConfig;
            if (! $config) {
                return;
            }

            $config->update(['is_enabled' => ! $config->is_enabled]);
            $this->isEnabled = $config->fresh()->is_enabled;
            $this->dispatch('success', $this->isEnabled ? 'Runners enabled.' : 'Runners disabled.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function deleteConfig()
    {
        try {
            $this->authorize('update', $this->server);
            $config = $this->server->githubRunnerConfig;
            if (! $config) {
                return;
            }

            if ($config->activeRunnerCount() > 0) {
                throw new \Exception('Cannot delete configuration while runners are active.');
            }

            $config->delete();
            $this->server->refresh();
            $this->loadConfig();
            $this->dispatch('success', 'GitHub Runner configuration deleted.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    #[On('cancel-github-runner-execution')]
    public function cancelExecution(int $executionId)
    {
        try {
            $this->authorize('update', $this->server);

            $execution = GithubRunnerExecution::where('id', $executionId)
                ->where('server_id', $this->server->id)
                ->with('config.githubApp')
                ->firstOrFail();

            if (! $execution->isActive()) {
                $this->dispatch('error', 'This execution is already finished.');

                return;
            }

            $server = $execution->server;

            if ($execution->pid && $server->isFunctional()) {
                instant_remote_process([
                    "kill {$execution->pid} 2>/dev/null || true",
                ], $server, throwError: false);
            }

            if ($execution->runner_dir && $server->isFunctional()) {
                instant_remote_process([
                    "rm -rf {$execution->runner_dir}",
                ], $server, throwError: false);
            }

            $this->deregisterRunnerFromGithub($execution);

            $execution->update([
                'status' => GithubRunnerStatus::Failed,
                'error_message' => 'Cancelled by user.',
                'completed_at' => now(),
            ]);

            $this->dispatch('success', "Runner {$execution->runner_name} cancelled.");
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    private function deregisterRunnerFromGithub(GithubRunnerExecution $execution): void
    {
        if (! $execution->runner_id) {
            return;
        }

        $githubApp = $execution->config?->githubApp;
        if (! $githubApp || $githubApp->is_public) {
            return;
        }

        $org = $githubApp->organization;
        if (! $org) {
            return;
        }

        try {
            $token = generateGithubInstallationToken($githubApp);
            $apiUrl = $githubApp->api_url ?? 'https://api.github.com';

            Http::GitHub($apiUrl, $token)
                ->delete("/orgs/{$org}/actions/runners/{$execution->runner_id}");
        } catch (\Throwable) {
            // Best-effort
        }
    }

    public function render()
    {
        return view('livewire.server.github-runners');
    }
}
