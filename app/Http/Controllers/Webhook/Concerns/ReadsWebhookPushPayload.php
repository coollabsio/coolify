<?php

namespace App\Http\Controllers\Webhook\Concerns;

use App\Exceptions\InvalidWebhookPayloadException;
use App\Models\Application;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Reads webhook payloads defensively.
 *
 * Git hosts omit the commit list (or send null) for some pushes, for example
 * branch creation, branch deletion and some system hooks. Such payloads must
 * not crash the handler and must not skip a deployment because of watch paths.
 *
 * The typed readers (webhookPayloadString(), webhookPayloadId(),
 * webhookCommitSha(), webhookPayloadUrl()) throw an
 * InvalidWebhookPayloadException for a value with a wrong type or format. The
 * handlers catch it and send a clean "Nothing to do." response, so a malformed
 * but correctly signed payload never causes a 500 or a deployment.
 */
trait ReadsWebhookPushPayload
{
    /**
     * Largest value of an integer database column (pull_request_id,
     * repository_project_id).
     */
    protected const WEBHOOK_MAX_DATABASE_INTEGER = 2147483647;

    /**
     * Length of the application_previews.pull_request_html_url column.
     */
    protected const WEBHOOK_MAX_URL_LENGTH = 255;

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
     * A string field of the payload. A missing, null or empty value gives null.
     *
     * @throws InvalidWebhookPayloadException When the value is not a string, or is missing and required.
     */
    protected function webhookPayloadString(mixed $payload, string $key, bool $required = false): ?string
    {
        $value = data_get($payload, $key);
        if ($value === null || $value === '') {
            if ($required) {
                throw InvalidWebhookPayloadException::forField($key);
            }

            return null;
        }

        if (! is_string($value)) {
            throw InvalidWebhookPayloadException::forField($key);
        }

        return $value;
    }

    /**
     * A positive integer id of the payload, sent as an integer or as a string
     * of digits. A missing or null value gives null.
     *
     * @throws InvalidWebhookPayloadException When the value is not a positive integer up to $max, or is missing and required.
     */
    protected function webhookPayloadId(mixed $payload, string $key, bool $required = false, int $max = PHP_INT_MAX): ?int
    {
        $value = data_get($payload, $key);
        if ($value === null) {
            if ($required) {
                throw InvalidWebhookPayloadException::forField($key);
            }

            return null;
        }

        if (is_string($value) && preg_match('/\A[1-9][0-9]{0,18}\z/', $value) === 1) {
            $value = filter_var($value, FILTER_VALIDATE_INT);
        }

        if (! is_int($value) || $value < 1 || $value > $max) {
            throw InvalidWebhookPayloadException::forField($key);
        }

        return $value;
    }

    /**
     * An id that Coolify stores in or compares with an integer database
     * column, for example a pull request id or a repository project id.
     *
     * @throws InvalidWebhookPayloadException
     */
    protected function webhookPayloadDatabaseId(mixed $payload, string $key, bool $required = false): ?int
    {
        return $this->webhookPayloadId($payload, $key, $required, self::WEBHOOK_MAX_DATABASE_INTEGER);
    }

    /**
     * A pull request or merge request id. The id is required.
     *
     * @throws InvalidWebhookPayloadException
     */
    protected function webhookPullRequestId(mixed $payload, string $key): int
    {
        return $this->webhookPayloadDatabaseId($payload, $key, required: true);
    }

    /**
     * A commit SHA of the payload: 7 to 64 hexadecimal characters. A missing,
     * null or empty value gives null.
     *
     * @throws InvalidWebhookPayloadException When the value is not a commit SHA.
     */
    protected function webhookCommitSha(mixed $payload, string $key): ?string
    {
        $value = data_get($payload, $key);
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || preg_match('/\A[0-9a-fA-F]{7,64}\z/', $value) !== 1) {
            throw InvalidWebhookPayloadException::forField($key);
        }

        return $value;
    }

    /**
     * An absolute http or https URL of the payload, for example a pull request
     * link that Coolify stores and shows in the UI. A missing, null or empty
     * value gives null.
     *
     * @throws InvalidWebhookPayloadException When the value is not a valid URL, or is missing and required.
     */
    protected function webhookPayloadUrl(mixed $payload, string $key, bool $required = false): ?string
    {
        $value = $this->webhookPayloadString($payload, $key, $required);
        if ($value === null) {
            return null;
        }

        $scheme = parse_url($value, PHP_URL_SCHEME);
        $host = parse_url($value, PHP_URL_HOST);
        if (
            strlen($value) > self::WEBHOOK_MAX_URL_LENGTH
            || preg_match('/[\s\x00-\x1F\x7F]/', $value) === 1
            || ! is_string($scheme)
            || ! in_array(strtolower($scheme), ['http', 'https'], true)
            || ! is_string($host)
            || $host === ''
        ) {
            throw InvalidWebhookPayloadException::forField($key);
        }

        return $value;
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
