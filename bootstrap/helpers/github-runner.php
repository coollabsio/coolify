<?php

use App\Models\GitHubRunnerSource;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Generate a JIT (Just-In-Time) configuration for an ephemeral GitHub Actions runner
 *
 * @param  GitHubRunnerSource  $source  The runner source containing GitHub App credentials
 * @param  array  $labels  Array of labels for the runner
 * @param  string  $runnerName  Unique name for the runner
 * @return array|null Returns array with 'encoded_jit_config' and 'runner' keys, or null on failure
 */
function generateRunnerJitConfig(GitHubRunnerSource $source, array $labels, string $runnerName): ?array
{
    try {
        $token = $source->generateInstallationToken();
        if (! $token) {
            Log::error('Failed to generate installation token for runner source: '.$source->id);

            return null;
        }

        $url = $source->is_organization_level
            ? "https://api.github.com/orgs/{$source->organization}/actions/runners/generate-jitconfig"
            : "https://api.github.com/repos/{$source->organization}/actions/runners/generate-jitconfig";

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
        ])->post($url, [
            'name' => $runnerName,
            'runner_group_id' => 1, // Default runner group
            'labels' => $labels,
            'work_folder' => '_work',
        ]);

        if ($response->successful()) {
            return $response->json();
        }

        Log::error('Failed to generate JIT config', [
            'source_id' => $source->id,
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        return null;
    } catch (\Exception $e) {
        Log::error('Exception generating JIT config: '.$e->getMessage(), [
            'source_id' => $source->id,
        ]);

        return null;
    }
}

/**
 * Generate a registration token for a GitHub Actions runner
 * This is an alternative to JIT config for non-ephemeral runners
 *
 * @param  GitHubRunnerSource  $source  The runner source containing GitHub App credentials
 * @return string|null The registration token, or null on failure
 */
function generateRunnerRegistrationToken(GitHubRunnerSource $source): ?string
{
    try {
        $token = $source->generateInstallationToken();
        if (! $token) {
            return null;
        }

        $url = $source->is_organization_level
            ? "https://api.github.com/orgs/{$source->organization}/actions/runners/registration-token"
            : "https://api.github.com/repos/{$source->organization}/actions/runners/registration-token";

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
        ])->post($url);

        if ($response->successful()) {
            return $response->json()['token'];
        }

        Log::error('Failed to generate registration token', [
            'source_id' => $source->id,
            'status' => $response->status(),
        ]);

        return null;
    } catch (\Exception $e) {
        Log::error('Exception generating registration token: '.$e->getMessage());

        return null;
    }
}

/**
 * List all runners registered to a GitHub organization or repository
 *
 * @param  GitHubRunnerSource  $source  The runner source
 * @return array Array of runners
 */
function listGitHubRunners(GitHubRunnerSource $source): array
{
    try {
        $token = $source->generateInstallationToken();
        if (! $token) {
            return [];
        }

        $url = $source->is_organization_level
            ? "https://api.github.com/orgs/{$source->organization}/actions/runners"
            : "https://api.github.com/repos/{$source->organization}/actions/runners";

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
        ])->get($url);

        if ($response->successful()) {
            return $response->json()['runners'] ?? [];
        }

        return [];
    } catch (\Exception $e) {
        Log::error('Exception listing runners: '.$e->getMessage());

        return [];
    }
}

/**
 * Remove a runner from GitHub
 *
 * @param  GitHubRunnerSource  $source  The runner source
 * @param  string  $runnerId  The GitHub runner ID
 * @return bool Success status
 */
function removeGitHubRunner(GitHubRunnerSource $source, string $runnerId): bool
{
    try {
        $token = $source->generateInstallationToken();
        if (! $token) {
            return false;
        }

        $url = $source->is_organization_level
            ? "https://api.github.com/orgs/{$source->organization}/actions/runners/{$runnerId}"
            : "https://api.github.com/repos/{$source->organization}/actions/runners/{$runnerId}";

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
        ])->delete($url);

        return $response->successful();
    } catch (\Exception $e) {
        Log::error('Exception removing runner: '.$e->getMessage());

        return false;
    }
}

/**
 * Validate a GitHub webhook signature
 *
 * @param  string  $payload  The raw webhook payload
 * @param  string  $signature  The X-Hub-Signature-256 header value
 * @param  string  $secret  The webhook secret
 * @return bool True if signature is valid
 */
function validateRunnerWebhookSignature(string $payload, string $signature, string $secret): bool
{
    if (empty($signature) || empty($secret)) {
        return false;
    }

    $computedSignature = 'sha256='.hash_hmac('sha256', $payload, $secret);

    return hash_equals($computedSignature, $signature);
}
