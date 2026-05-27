<?php

namespace App\Http\Controllers\Webhook\Bitbucket;

use App\Http\Controllers\Webhook\Concerns\DetectsSkipDeployCommits;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BitbucketDataCenterWebhookVariant implements BitbucketWebhookVariant
{
    use DetectsSkipDeployCommits;

    public function supports(Request $request): bool
    {
        $event = $this->eventKey($request);

        if ($event !== '' && $this->isDataCenterEvent($event)) {
            return true;
        }

        return filled($request->collect()->get('eventKey'));
    }

    public function eventKey(Request $request): string
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

    public function handledEventKeys(): array
    {
        return [
            'repo:refs_changed',
            'pr:opened',
            'pr:from_ref_updated',
            'pr:modified',
            'pr:declined',
            'pr:merged',
            'pr:deleted',
        ];
    }

    public function parse(Request $request): ?BitbucketWebhookContext
    {
        $payload = $request->collect();
        $rawEvent = $this->eventKey($request);
        $repositoryIdentifiers = BitbucketRepositoryIdentifiers::fromPayload($payload);

        if ($rawEvent === 'repo:refs_changed') {
            $change = data_get($payload, 'changes.0');
            if (data_get($change, 'type') !== 'UPDATE') {
                return null;
            }

            $branch = data_get($change, 'ref.displayId');
            $commit = data_get($change, 'toHash');

            if (! filled($branch)) {
                return null;
            }

            return new BitbucketWebhookContext(
                rawEvent: $rawEvent,
                action: 'push',
                branch: (string) $branch,
                repositoryIdentifiers: $repositoryIdentifiers,
                commit: filled($commit) ? (string) $commit : null,
            );
        }

        $action = match ($rawEvent) {
            'pr:opened', 'pr:from_ref_updated', 'pr:modified' => 'preview_deploy',
            'pr:declined', 'pr:merged', 'pr:deleted' => 'preview_close',
            default => null,
        };

        if ($action === null) {
            return null;
        }

        $pullRequest = data_get($payload, 'pullRequest');
        if (! is_array($pullRequest)) {
            return null;
        }

        $branch = data_get($pullRequest, 'toRef.displayId');
        $pullRequestTitle = data_get($pullRequest, 'title');
        $commit = data_get($pullRequest, 'fromRef.latestCommit');
        $pullRequestId = data_get($pullRequest, 'id');
        $pullRequestHtmlUrl = data_get($pullRequest, 'links.self.0.href');

        if (! filled($branch)) {
            return null;
        }

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

    public function unhandledBranchMessage(string $rawEvent): string
    {
        if ($rawEvent === 'repo:refs_changed') {
            return 'Nothing to do. Ref change is not a branch push update.';
        }

        return 'Nothing to do. No branch found in the request.';
    }

    private function isDataCenterEvent(string $event): bool
    {
        return in_array($event, $this->handledEventKeys(), true)
            || str_starts_with($event, 'pr:');
    }
}
