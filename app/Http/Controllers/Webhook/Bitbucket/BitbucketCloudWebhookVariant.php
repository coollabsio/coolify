<?php

namespace App\Http\Controllers\Webhook\Bitbucket;

use App\Http\Controllers\Webhook\Concerns\DetectsSkipDeployCommits;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BitbucketCloudWebhookVariant implements BitbucketWebhookVariant
{
    use DetectsSkipDeployCommits;

    public function supports(Request $request): bool
    {
        $event = $this->eventKey($request);
        if ($event !== '' && $this->isCloudEvent($event)) {
            return true;
        }

        $payload = $request->collect();

        return filled(data_get($payload, 'repository.full_name'))
            || filled(data_get($payload, 'push.changes'));
    }

    public function eventKey(Request $request): string
    {
        $header = $request->header('X-Event-Key');
        if (filled($header)) {
            return Str::lower($header);
        }

        return '';
    }

    public function handledEventKeys(): array
    {
        return [
            'repo:push',
            'pullrequest:created',
            'pullrequest:updated',
            'pullrequest:rejected',
            'pullrequest:fulfilled',
        ];
    }

    public function parse(Request $request): ?BitbucketWebhookContext
    {
        $payload = $request->collect();
        $rawEvent = $this->eventKey($request);
        $repositoryIdentifiers = BitbucketRepositoryIdentifiers::fromPayload($payload);

        if ($rawEvent === 'repo:push') {
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

            if (! filled($branch)) {
                return null;
            }

            return new BitbucketWebhookContext(
                rawEvent: $rawEvent,
                action: 'push',
                branch: (string) $branch,
                repositoryIdentifiers: $repositoryIdentifiers,
                commit: filled($commit) ? (string) $commit : null,
                skipDeployCommits: $skipDeployCommits,
            );
        }

        if (in_array($rawEvent, ['pullrequest:created', 'pullrequest:updated', 'pullrequest:rejected', 'pullrequest:fulfilled'], true)) {
            $pullRequest = data_get($payload, 'pullrequest');
            if (! is_array($pullRequest)) {
                return null;
            }

            $branch = data_get($pullRequest, 'destination.branch.name');
            $pullRequestTitle = data_get($pullRequest, 'title');
            $commit = data_get($pullRequest, 'source.commit.hash');
            $pullRequestId = data_get($pullRequest, 'id');
            $pullRequestHtmlUrl = data_get($pullRequest, 'links.html.href');

            if (! filled($branch)) {
                return null;
            }

            $action = in_array($rawEvent, ['pullrequest:rejected', 'pullrequest:fulfilled'], true)
                ? 'preview_close'
                : 'preview_deploy';

            return new BitbucketWebhookContext(
                rawEvent: $rawEvent,
                action: $action,
                branch: (string) $branch,
                repositoryIdentifiers: $repositoryIdentifiers,
                commit: filled($commit) ? (string) $commit : null,
                skipDeployPr: self::shouldSkipDeployAny([$pullRequestTitle]),
                pullRequestId: filled($pullRequestId) ? (int) $pullRequestId : null,
                pullRequestHtmlUrl: filled($pullRequestHtmlUrl) ? (string) $pullRequestHtmlUrl : null,
            );
        }

        return null;
    }

    public function unhandledBranchMessage(string $rawEvent): string
    {
        return 'Nothing to do. No branch found in the request.';
    }

    private function isCloudEvent(string $event): bool
    {
        return in_array($event, $this->handledEventKeys(), true);
    }
}
