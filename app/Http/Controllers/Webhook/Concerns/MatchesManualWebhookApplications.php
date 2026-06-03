<?php

namespace App\Http\Controllers\Webhook\Concerns;

use App\Models\Application;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

trait MatchesManualWebhookApplications
{
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
        } elseif (Str::startsWith($gitRepository, 'git@') && str_contains($gitRepository, ':')) {
            $path = Str::after($gitRepository, ':');
            // scp-style SSH URLs embed a custom port as "git@host:2222/owner/repo".
            // Strip the leading numeric port segment so the path matches the webhook
            // payload's owner/repo, consistent with convertGitUrl() in shared.php.
            $path = preg_replace('#^\d+/#', '', $path) ?? $path;
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
