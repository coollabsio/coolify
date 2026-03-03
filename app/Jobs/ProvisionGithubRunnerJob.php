<?php

namespace App\Jobs;

use App\Enums\GithubRunnerStatus;
use App\Models\GithubApp;
use App\Models\GithubRunnerConfig;
use App\Models\GithubRunnerExecution;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Visus\Cuid2\Cuid2;

class ProvisionGithubRunnerJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300;

    public $tries = 3;

    public function backoff(): array
    {
        return [5, 15, 30];
    }

    public function __construct(
        public int $githubAppId,
        public array $workflowJobPayload,
        public string $organizationLogin,
        public int $repositoryId = 0,
    ) {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        $workflowJobId = data_get($this->workflowJobPayload, 'id');

        // Idempotency: skip if already provisioning for this job
        if (GithubRunnerExecution::where('workflow_job_id', $workflowJobId)->exists()) {
            return;
        }

        $githubApp = GithubApp::find($this->githubAppId);
        if (! $githubApp) {
            return;
        }

        $requestedLabels = data_get($this->workflowJobPayload, 'labels', []);

        // Find a matching server with capacity
        $config = $this->findMatchingConfig($githubApp, $requestedLabels);
        if (! $config) {
            ray('No matching GitHub runner config found for labels: '.implode(', ', $requestedLabels));

            return;
        }

        $runnerName = 'coolify-'.((string) new Cuid2(7));
        $runnerDir = "{$config->runner_base_dir}/{$runnerName}";

        $execution = GithubRunnerExecution::create([
            'server_id' => $config->server_id,
            'github_runner_config_id' => $config->id,
            'status' => GithubRunnerStatus::Queued,
            'runner_name' => $runnerName,
            'runner_dir' => $runnerDir,
            'workflow_job_id' => $workflowJobId,
            'workflow_name' => data_get($this->workflowJobPayload, 'workflow_name'),
            'repository_full_name' => data_get($this->workflowJobPayload, 'repository.full_name',
                data_get($this->workflowJobPayload, 'head_repository.full_name')
            ),
        ]);

        try {
            $execution->update(['status' => GithubRunnerStatus::Provisioning]);

            // Ensure a Coolify-managed runner group exists and the repo has access
            $runnerGroupId = $this->ensureRunnerGroup($githubApp);
            $this->ensureRepositoryInRunnerGroup($githubApp, $runnerGroupId);

            // Generate JIT config via GitHub API
            ['encoded_jit_config' => $jitConfig, 'runner_id' => $runnerId] = $this->generateJitConfig($githubApp, $config, $runnerName, $requestedLabels, $runnerGroupId);

            // Provision runner on server via SSH
            $pid = $this->provisionRunner($config, $runnerName, $runnerDir, $jitConfig);

            $execution->update([
                'status' => GithubRunnerStatus::Running,
                'pid' => $pid,
                'runner_id' => $runnerId,
                'started_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $execution->update([
                'status' => GithubRunnerStatus::Failed,
                'error_message' => $e->getMessage(),
            ]);

            // Attempt cleanup of the runner directory on the server
            try {
                $server = $config->server;
                instant_remote_process(["rm -rf {$runnerDir}"], $server, throwError: false);
            } catch (\Throwable) {
                // Best-effort cleanup
            }

            throw $e;
        }
    }

    private function findMatchingConfig(GithubApp $githubApp, array $requestedLabels): ?GithubRunnerConfig
    {
        return GithubRunnerConfig::query()
            ->where('github_app_id', $githubApp->id)
            ->whereHas('githubApp', fn ($q) => $q->where('organization', $this->organizationLogin))
            ->where('is_enabled', true)
            ->with('server')
            ->get()
            ->filter(fn ($config) => $config->matchesLabels($requestedLabels))
            ->filter(fn ($config) => $config->server->isFunctional())
            ->filter(fn ($config) => $config->hasCapacity())
            ->sortBy(fn ($config) => $config->activeRunnerCount())
            ->first();
    }

    private function ensureRunnerGroup(GithubApp $githubApp): int
    {
        $token = generateGithubInstallationToken($githubApp);
        $apiUrl = $githubApp->api_url ?? 'https://api.github.com';

        if ($githubApp->runner_group_id) {
            // Ensure existing group allows public repos
            Http::withHeaders([
                'Authorization' => "Bearer {$token}",
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
            ])->patch("{$apiUrl}/orgs/{$githubApp->organization}/actions/runner-groups/{$githubApp->runner_group_id}", [
                'allows_public_repositories' => true,
            ]);

            return $githubApp->runner_group_id;
        }

        $groupName = 'Coolify-'.((string) new Cuid2(7));

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
        ])->post("{$apiUrl}/orgs/{$githubApp->organization}/actions/runner-groups", [
            'name' => $groupName,
            'visibility' => 'selected',
            'allows_public_repositories' => true,
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException(
                'Failed to create runner group: '.data_get($response->json(), 'message', $response->body())
            );
        }

        $runnerGroupId = (int) data_get($response->json(), 'id');
        $githubApp->update(['runner_group_id' => $runnerGroupId]);

        return $runnerGroupId;
    }

    private function ensureRepositoryInRunnerGroup(GithubApp $githubApp, int $runnerGroupId): void
    {
        if ($this->repositoryId <= 0) {
            ray("Skipping runner group repo assignment — repositoryId is {$this->repositoryId}");

            return;
        }

        $token = generateGithubInstallationToken($githubApp);
        $apiUrl = $githubApp->api_url ?? 'https://api.github.com';
        $url = "{$apiUrl}/orgs/{$githubApp->organization}/actions/runner-groups/{$runnerGroupId}/repositories/{$this->repositoryId}";

        ray("Adding repository {$this->repositoryId} to runner group {$runnerGroupId}: PUT {$url}");

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
        ])->withBody('', 'application/json')->put($url);

        if (! $response->successful()) {
            ray('Failed to add repository to runner group: '.$response->status().' '.data_get($response->json(), 'message', $response->body()));
        }
    }

    private function generateJitConfig(GithubApp $githubApp, GithubRunnerConfig $config, string $runnerName, array $requestedLabels, int $runnerGroupId): array
    {
        $token = generateGithubInstallationToken($githubApp);
        $apiUrl = $githubApp->api_url ?? 'https://api.github.com';

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
        ])->post("{$apiUrl}/orgs/{$config->organization}/actions/runners/generate-jitconfig", [
            'name' => $runnerName,
            'runner_group_id' => $runnerGroupId,
            'labels' => $requestedLabels,
            'work_folder' => '_work',
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException(
                'Failed to generate JIT runner config: '.data_get($response->json(), 'message', $response->body())
            );
        }

        return [
            'encoded_jit_config' => data_get($response->json(), 'encoded_jit_config'),
            'runner_id' => data_get($response->json(), 'runner.id'),
        ];
    }

    private function provisionRunner(GithubRunnerConfig $config, string $runnerName, string $runnerDir, string $jitConfig): int
    {
        $server = $config->server;
        $user = $config->runner_user;
        $baseDir = $config->runner_base_dir;
        $cacheDir = "{$baseDir}/.cache";
        // Detect architecture from server
        $uname = trim(instant_remote_process(['uname -m'], $server));
        $arch = $uname === 'aarch64' ? 'arm64' : 'x64';

        $version = $config->runner_version ?? $this->getLatestRunnerVersion($config, $arch);

        // Ensure runner user and directories exist
        instant_remote_process([
            "id -u {$user} &>/dev/null || useradd -m -s /bin/bash {$user}",
            "usermod -aG docker {$user}",
            "mkdir -p {$cacheDir}",
            "mkdir -p {$runnerDir}",
        ], $server);

        // Download runner binary if not cached
        $tarball = "actions-runner-linux-{$arch}-{$version}.tar.gz";
        instant_remote_process([
            "if [ ! -f {$cacheDir}/{$tarball} ]; then curl -sL https://github.com/actions/runner/releases/download/v{$version}/{$tarball} -o {$cacheDir}/{$tarball}; fi",
            "tar xzf {$cacheDir}/{$tarball} -C {$runnerDir}",
            "chown -R {$user}:{$user} {$runnerDir}",
        ], $server);

        // Start the JIT runner in background
        $output = instant_remote_process([
            "cd {$runnerDir} && sudo -u {$user} nohup ./run.sh --jitconfig {$jitConfig} > {$runnerDir}/runner.log 2>&1 & echo \$!",
        ], $server);

        $pid = (int) trim($output);
        if ($pid <= 0) {
            throw new \RuntimeException('Failed to start runner process — no PID returned.');
        }

        return $pid;
    }

    private function getLatestRunnerVersion(GithubRunnerConfig $config, string $arch): string
    {
        $githubApp = GithubApp::find($this->githubAppId);
        $apiUrl = $githubApp?->api_url ?? 'https://api.github.com';

        try {
            $token = generateGithubInstallationToken($githubApp);
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$token}",
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
            ])->get("{$apiUrl}/orgs/{$config->organization}/actions/runners/downloads");

            if ($response->successful()) {
                $download = collect($response->json())
                    ->first(fn ($d) => data_get($d, 'os') === 'linux' && data_get($d, 'architecture') === $arch);

                if ($download) {
                    // Extract version from filename like "actions-runner-linux-x64-2.321.0.tar.gz"
                    preg_match('/(\d+\.\d+\.\d+)/', data_get($download, 'filename', ''), $matches);
                    if (! empty($matches[1])) {
                        return $matches[1];
                    }
                }
            }
        } catch (\Throwable) {
            // Fall through to default
        }

        return '2.321.0';
    }
}
