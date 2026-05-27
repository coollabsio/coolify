<?php

namespace App\Http\Controllers\Webhook\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

trait ParsesBitbucketWebhookEvents
{
    /**
     * Bitbucket Cloud + Data Center / Server event keys (before normalization).
     */
    public static function handledBitbucketWebhookEventKeys(): array
    {
        return [
            'repo:push',
            'repo:refs_changed',
            'pullrequest:created',
            'pullrequest:updated',
            'pullrequest:rejected',
            'pullrequest:fulfilled',
            'pr:opened',
            'pr:from_ref_updated',
            'pr:modified',
            'pr:declined',
            'pr:merged',
            'pr:deleted',
        ];
    }

    public static function bitbucketEventKey(Request $request): string
    {
        $header = $request->header('X-Event-Key');
        if (filled($header)) {
            return Str::lower($header);
        }

        $bodyKey = $request->collect()->get('eventKey');
        if (filled($bodyKey)) {
            return Str::lower((string) $bodyKey);
        }

        return '';
    }

    /**
     * Map Bitbucket Data Center / Server keys to Cloud-equivalent handlers.
     */
    public static function normalizeBitbucketEventKey(string $event): string
    {
        return match (Str::lower($event)) {
            'repo:refs_changed' => 'repo:push',
            'pr:opened' => 'pullrequest:created',
            'pr:from_ref_updated', 'pr:modified' => 'pullrequest:updated',
            'pr:declined', 'pr:deleted' => 'pullrequest:rejected',
            'pr:merged' => 'pullrequest:fulfilled',
            default => Str::lower($event),
        };
    }

    /**
     * @return array<int, string>
     */
    public static function bitbucketRepositoryIdentifiers(Collection $payload): array
    {
        $identifiers = collect();

        $fullName = data_get($payload, 'repository.full_name');
        if (filled($fullName)) {
            $identifiers->push($fullName);
        }

        $repository = data_get($payload, 'repository')
            ?? data_get($payload, 'pullRequest.toRef.repository')
            ?? data_get($payload, 'pullRequest.fromRef.repository')
            ?? data_get($payload, 'pullrequest.toRef.repository')
            ?? data_get($payload, 'pullrequest.fromRef.repository');

        if (! is_array($repository)) {
            return $identifiers->unique()->filter()->values()->all();
        }

        $projectKey = data_get($repository, 'project.key');
        $slug = data_get($repository, 'slug');

        if (filled($projectKey) && filled($slug)) {
            $identifiers->push("{$projectKey}/{$slug}");
            $identifiers->push(strtolower((string) $projectKey).'/'.$slug);
        }

        if (filled($slug)) {
            $identifiers->push($slug);
        }

        foreach (data_get($repository, 'links.clone', []) as $clone) {
            $href = data_get($clone, 'href');
            if (! is_string($href) || $href === '') {
                continue;
            }

            if (preg_match('#[:/]([^/]+/[^/]+?)(?:\.git)?$#', $href, $matches)) {
                $identifiers->push($matches[1]);
            }

            $path = preg_replace('/\.git$/', '', parse_url($href, PHP_URL_PATH) ?? '');
            $path = trim(str_replace(':', '/', (string) $path), '/');
            if ($path === '') {
                continue;
            }

            $segments = explode('/', $path);
            if (count($segments) >= 2) {
                $identifiers->push(implode('/', array_slice($segments, -2)));
            }
            $identifiers->push(end($segments));
        }

        return $identifiers->unique()->filter()->values()->all();
    }

    /**
     * @return array{
     *     branch: string,
     *     commit: ?string,
     *     skip_deploy_commits: bool,
     *     pull_request_id: ?int,
     *     pull_request_html_url: ?string,
     *     pull_request_title: ?string,
     *     skip_deploy_pr: bool,
     *     repository_identifiers: array<int, string>
     * }|null
     */
    public static function parseBitbucketWebhookPayload(Collection $payload, string $rawEvent): ?array
    {
        $event = self::normalizeBitbucketEventKey($rawEvent);
        $repositoryIdentifiers = self::bitbucketRepositoryIdentifiers($payload);

        if ($event === 'repo:push') {
            if (Str::lower($rawEvent) === 'repo:refs_changed') {
                $change = data_get($payload, 'changes.0');
                if (data_get($change, 'type') !== 'UPDATE') {
                    return null;
                }

                $branch = data_get($change, 'ref.displayId');
                $commit = data_get($change, 'toHash');
                $skipDeployCommits = false;
            } else {
                $branch = data_get($payload, 'push.changes.0.new.name');
                $commit = data_get($payload, 'push.changes.0.new.target.hash');
                $skipDeployCommits = self::shouldSkipDeploy(
                    collect(data_get($payload, 'push.changes', []))
                        ->flatMap(fn ($change) => data_get($change, 'commits', []))
                        ->pluck('message')
                        ->filter()
                        ->values()
                        ->all()
                );
            }

            if (! filled($branch)) {
                return null;
            }

            return [
                'branch' => (string) $branch,
                'commit' => filled($commit) ? (string) $commit : null,
                'skip_deploy_commits' => $skipDeployCommits,
                'pull_request_id' => null,
                'pull_request_html_url' => null,
                'pull_request_title' => null,
                'skip_deploy_pr' => false,
                'repository_identifiers' => $repositoryIdentifiers,
            ];
        }

        if (in_array($event, ['pullrequest:created', 'pullrequest:updated', 'pullrequest:rejected', 'pullrequest:fulfilled'], true)) {
            $pullRequest = data_get($payload, 'pullRequest') ?? data_get($payload, 'pullrequest');

            if (! is_array($pullRequest)) {
                return null;
            }

            $branch = data_get($pullRequest, 'destination.branch.name')
                ?? data_get($pullRequest, 'toRef.displayId');
            $pullRequestTitle = data_get($pullRequest, 'title');
            $commit = data_get($pullRequest, 'source.commit.hash')
                ?? data_get($pullRequest, 'fromRef.latestCommit');
            $pullRequestId = data_get($pullRequest, 'id');
            $pullRequestHtmlUrl = data_get($pullRequest, 'links.html.href')
                ?? data_get($pullRequest, 'links.self.0.href');

            if (! filled($branch)) {
                return null;
            }

            return [
                'branch' => (string) $branch,
                'commit' => filled($commit) ? (string) $commit : null,
                'skip_deploy_commits' => false,
                'pull_request_id' => filled($pullRequestId) ? (int) $pullRequestId : null,
                'pull_request_html_url' => filled($pullRequestHtmlUrl) ? (string) $pullRequestHtmlUrl : null,
                'pull_request_title' => filled($pullRequestTitle) ? (string) $pullRequestTitle : null,
                'skip_deploy_pr' => self::shouldSkipDeployAny([$pullRequestTitle]),
                'repository_identifiers' => $repositoryIdentifiers,
            ];
        }

        return null;
    }
}
