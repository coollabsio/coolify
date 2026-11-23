<?php

namespace App\Services\GithubRunner;

use App\Models\GithubApp;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Organization-level GitHub Actions runner API calls made with the App installation token.
 */
class GithubRunnerApi
{
    public function __construct(private GithubApp $githubApp) {}

    /**
     * Finds or creates the runner group of the App. A new group lets every repository of the organization,
     * public ones too, use it; runners refuse pull request jobs unless the runner config allows them.
     * Coolify does not change an existing group, so restrictions an organization admin set on GitHub stay.
     * All GitHub plans can create runner groups, so a failed creation is an error. Coolify never falls back
     * to the Default group, because that group can give the runners access to more repositories.
     *
     * @return array{id: int, is_default: bool}
     */
    public function ensureRunnerGroup(): array
    {
        $org = $this->organization();

        if ($this->githubApp->runner_group_id) {
            $response = $this->client()->get("/orgs/{$org}/actions/runner-groups/{$this->githubApp->runner_group_id}");
            if ($response->successful()) {
                return ['id' => (int) $this->githubApp->runner_group_id, 'is_default' => (bool) $response->json('default')];
            }
            if ($response->status() !== 404) {
                throw new RuntimeException('Could not read the runner group: '.$this->errorMessage($response));
            }
        }

        $response = $this->client()->post("/orgs/{$org}/actions/runner-groups", [
            'name' => 'Coolify '.$this->githubApp->uuid,
            'visibility' => 'all',
            'allows_public_repositories' => true,
        ]);
        if (! $response->successful() || ! $response->json('id')) {
            throw new RuntimeException('Could not create a runner group: '.$this->errorMessage($response));
        }
        $group = ['id' => (int) $response->json('id'), 'is_default' => false];

        $this->githubApp->update(['runner_group_id' => $group['id']]);

        return $group;
    }

    /**
     * @param  array<int, string>  $labels
     * @return array{runner_id: int, encoded_jit_config: string}
     */
    public function generateJitConfig(string $runnerName, array $labels, int $runnerGroupId): array
    {
        $response = $this->client()->post("/orgs/{$this->organization()}/actions/runners/generate-jitconfig", [
            'name' => $runnerName,
            'runner_group_id' => $runnerGroupId,
            'labels' => $labels,
            'work_folder' => '_work',
        ]);

        if (! $response->successful() || blank($response->json('encoded_jit_config'))) {
            throw new RuntimeException('Could not register the runner on GitHub: '.$this->errorMessage($response));
        }

        return [
            'runner_id' => (int) $response->json('runner.id'),
            'encoded_jit_config' => (string) $response->json('encoded_jit_config'),
        ];
    }

    /**
     * The event that started a workflow run, for example "push" or "pull_request". The `workflow_job`
     * webhook does not contain it. A short timeout keeps the webhook response fast.
     */
    public function workflowRunEvent(string $repositoryFullName, int $runId): string
    {
        $repository = implode('/', array_map('rawurlencode', explode('/', $repositoryFullName, 2)));
        $response = $this->client()->timeout(5)->get("/repos/{$repository}/actions/runs/{$runId}");

        if (! $response->successful() || ! is_string($response->json('event'))) {
            throw new RuntimeException('Could not read the workflow run: '.$this->errorMessage($response));
        }

        return $response->json('event');
    }

    /**
     * Cancels the workflow run of a job that has not completed, so GitHub does not keep it in progress
     * on a runner that is gone. GitHub has no API to cancel one job. A normal cancel (not force-cancel)
     * still runs the `always()` steps of the other jobs. Needs the repository permission "Actions": write.
     *
     * @return bool whether Coolify asked GitHub to cancel the run
     */
    public function cancelUnfinishedWorkflowRun(string $repositoryFullName, int $workflowJobId): bool
    {
        $repository = implode('/', array_map('rawurlencode', explode('/', $repositoryFullName, 2)));
        $job = $this->client()->get("/repos/{$repository}/actions/jobs/{$workflowJobId}");
        if ($job->status() === 404 || $job->json('status') === 'completed') {
            return false;
        }
        if (! $job->successful() || ! is_int($job->json('run_id'))) {
            throw new RuntimeException('Could not read the workflow job: '.$this->errorMessage($job));
        }

        $response = $this->client()->post("/repos/{$repository}/actions/runs/{$job->json('run_id')}/cancel");
        if ($response->status() === 409) {
            return false;
        }
        if ($response->status() === 403) {
            throw new RuntimeException('Could not cancel the workflow run: the GitHub App needs the repository permission "Actions": write ('.$this->errorMessage($response).').');
        }
        if (! $response->successful()) {
            throw new RuntimeException('Could not cancel the workflow run: '.$this->errorMessage($response));
        }

        return true;
    }

    /**
     * Removes a runner from GitHub. GitHub removes JIT runners itself after their job, so 404 is not an error.
     */
    public function deleteRunner(int $runnerId): void
    {
        $response = $this->client()->delete("/orgs/{$this->organization()}/actions/runners/{$runnerId}");

        if (! $response->successful() && $response->status() !== 404) {
            throw new RuntimeException('Could not remove the runner from GitHub: '.$this->errorMessage($response));
        }
    }

    private function client(): PendingRequest
    {
        return Http::GitHub($this->githubApp->api_url, generateGithubInstallationToken($this->githubApp))->timeout(30);
    }

    private function organization(): string
    {
        $organization = trim((string) $this->githubApp->organization);
        if ($organization === '') {
            throw new RuntimeException('GitHub Actions runners need a GitHub App that belongs to an organization.');
        }

        return rawurlencode($organization);
    }

    private function errorMessage(Response $response): string
    {
        return (string) ($response->json('message') ?? "HTTP {$response->status()}");
    }
}
