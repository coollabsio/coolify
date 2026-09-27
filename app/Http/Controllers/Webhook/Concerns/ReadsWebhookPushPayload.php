<?php

namespace App\Http\Controllers\Webhook\Concerns;

use App\Models\Application;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Reads push webhook payloads defensively.
 *
 * Git hosts omit the commit list (or send null) for some pushes, for example
 * branch creation, branch deletion and some system hooks. Such payloads must
 * not crash the handler and must not skip a deployment because of watch paths.
 */
trait ReadsWebhookPushPayload
{
    /**
     * Branch name from a ref such as "refs/heads/main", or null when the ref is
     * missing or not a string.
     */
    protected function webhookPushBranch(mixed $ref): ?string
    {
        if (! is_string($ref) || $ref === '') {
            return null;
        }

        $branch = Str::after($ref, 'refs/heads/');

        return $branch === '' ? null : $branch;
    }

    /**
     * A non-empty string from the payload, or null for any other value.
     */
    protected function webhookString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Files that the commits of a push add, remove or modify.
     *
     * Returns null when the payload has no commit list. The changed files are
     * then unknown, and watch paths must not skip the deployment.
     *
     * @return Collection<int, string>|null
     */
    protected function webhookPushChangedFiles(mixed $payload): ?Collection
    {
        $commits = data_get($payload, 'commits');
        if (! is_array($commits)) {
            return null;
        }

        return collect($commits)
            ->filter(fn (mixed $commit): bool => is_array($commit))
            ->flatMap(fn (array $commit): array => collect(['added', 'removed', 'modified'])
                ->flatMap(fn (string $type): array => is_array($commit[$type] ?? null) ? $commit[$type] : [])
                ->all())
            ->filter(fn (mixed $file): bool => is_string($file) && $file !== '')
            ->unique()
            ->values();
    }

    /**
     * Commit messages of a push. Values that are not strings are ignored.
     *
     * @return array<int, string>
     */
    protected function webhookPushCommitMessages(mixed $payload, string $commitsPath = 'commits'): array
    {
        $commits = data_get($payload, $commitsPath);
        if (! is_array($commits)) {
            return [];
        }

        return collect($commits)
            ->map(fn (mixed $commit): mixed => is_array($commit) ? ($commit['message'] ?? null) : null)
            ->filter(fn (mixed $message): bool => is_string($message))
            ->values()
            ->all();
    }

    /**
     * True when the push deletes the branch. Such a push has no commit to deploy.
     */
    protected function isWebhookBranchDeletionPush(mixed $payload): bool
    {
        if (data_get($payload, 'deleted') === true) {
            return true;
        }

        $after = data_get($payload, 'after');

        return is_string($after) && preg_match('/\A0+\z/', $after) === 1;
    }

    /**
     * True when the push must deploy the application with respect to its watch
     * paths. Unknown changed files (no commit list) do not skip the deployment.
     *
     * @param  Collection<int, string>|null  $changedFiles
     */
    protected function webhookPushMatchesWatchPaths(Application $application, ?Collection $changedFiles): bool
    {
        if (blank($application->watch_paths) || $changedFiles === null) {
            return true;
        }

        return $application->isWatchPathsTriggered($changedFiles);
    }
}
