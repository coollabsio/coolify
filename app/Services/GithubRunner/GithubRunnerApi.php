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
     * Finds or creates the runner group of the App. The group is limited to private repositories.
     * Organizations without custom runner groups (GitHub Free) fall back to the Default group.
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
            'visibility' => 'private',
            'allows_public_repositories' => false,
        ]);
        $group = ['id' => (int) $response->json('id'), 'is_default' => false];

        if (! $response->successful()) {
            $groups = $this->client()->get("/orgs/{$org}/actions/runner-groups");
            $default = collect($groups->json('runner_groups', []))->firstWhere('default', true);
            if (! $groups->successful() || ! $default) {
                throw new RuntimeException('Could not create a runner group: '.$this->errorMessage($response));
            }
            $group = ['id' => (int) $default['id'], 'is_default' => true];
        }

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
