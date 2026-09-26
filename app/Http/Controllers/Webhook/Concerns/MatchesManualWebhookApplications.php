<?php

namespace App\Http\Controllers\Webhook\Concerns;

use App\Models\Application;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;

trait MatchesManualWebhookApplications
{
    use ThrottlesManualWebhookFailures;

    protected function manualWebhookRepositoryFullName(mixed $fullName): ?string
    {
        if (! is_string($fullName)) {
            return null;
        }

        $fullName = trim($fullName, " \t\n\r\0\x0B/");

        if ($fullName === '') {
            return null;
        }

        if (! preg_match('/\A[A-Za-z0-9_.-]+(?:\/[A-Za-z0-9_.-]+)+\z/', $fullName)) {
            return null;
        }

        return $this->normalizeManualWebhookRepositoryPath($fullName);
    }

    /**
     * @return Collection<int, Application>
     */
    protected function manualWebhookApplications(Builder $query, string $fullName): Collection
    {
        return $query->get()
            ->filter(fn (Application $application): bool => $this->manualWebhookRepositoryMatches($application->git_repository, $fullName))
            ->values();
    }

    protected function manualWebhookRepositoryMatches(?string $gitRepository, string $fullName): bool
    {
        $repositoryPath = $this->canonicalManualWebhookRepository($gitRepository);

        if ($repositoryPath === null) {
            return false;
        }

        // Git hosts (GitHub, GitLab, Gitea, Bitbucket) treat owner/repo names
        // case-insensitively, so compare the canonical paths case-insensitively.
        return hash_equals(mb_strtolower($fullName), mb_strtolower($repositoryPath));
    }

    /**
     * @return array{status: string, message: string}
     */
    protected function unauthenticatedManualWebhookFailurePayload(): array
    {
        return [
            'status' => 'failed',
            'message' => 'Invalid signature.',
        ];
    }

    /**
     * Respond to a delivery that could not be authenticated (no matching
     * application or no signature) and count it as a failed attempt.
     *
     * Deliveries without a matching application are counted too: the failure
     * key is scoped to the repository and branch, so this cannot lock out other
     * applications, and it keeps the 429 response from revealing which
     * repositories exist in this instance.
     */
    protected function unauthenticatedManualWebhookResponse(string $failureKey): Response
    {
        $this->recordManualWebhookFailure($failureKey);

        return response([$this->unauthenticatedManualWebhookFailurePayload()]);
    }

    protected function manualWebhookResponse(Collection $payloads, string $failureKey): Response
    {
        $failure = $this->unauthenticatedManualWebhookFailurePayload();
        $authorizedPayloads = $payloads->reject(fn (array $payload): bool => $payload === $failure)->values();
        if ($authorizedPayloads->isEmpty() && $payloads->isNotEmpty()) {
            return $this->unauthenticatedManualWebhookResponse($failureKey);
        }

        return response($authorizedPayloads);
    }

    protected function canonicalManualWebhookRepository(?string $gitRepository): ?string
    {
        if (! is_string($gitRepository)) {
            return null;
        }

        $gitRepository = trim($gitRepository);

        if ($gitRepository === '') {
            return null;
        }

        $path = null;
        $parts = parse_url($gitRepository);

        if (is_array($parts) && isset($parts['scheme'])) {
            $path = data_get($parts, 'path');
        } elseif (($scp = parseScpStyleGitUrl($gitRepository)) !== null) {
            $path = $scp['path'];
        } else {
            $path = $gitRepository;
        }

        if (! is_string($path) || $path === '') {
            return null;
        }

        return $this->normalizeManualWebhookRepositoryPath($path);
    }

    protected function normalizeManualWebhookRepositoryPath(string $path): string
    {
        $path = trim($path);
        $path = strtok($path, '?#') ?: $path;
        $path = trim($path, '/');
        $path = preg_replace('/\.git\z/i', '', $path) ?? $path;

        return $path;
    }
}
