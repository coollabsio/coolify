<?php

namespace App\Http\Controllers\Webhook\Bitbucket;

use App\Http\Controllers\Webhook\Concerns\DetectsSkipDeployCommits;
use App\Http\Controllers\Webhook\Concerns\ReadsWebhookPushPayload;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BitbucketDataCenterWebhookVariant implements BitbucketWebhookVariant
{
    use DetectsSkipDeployCommits;
    use ReadsWebhookPushPayload;

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

            $branch = $this->webhookString(data_get($change, 'ref.displayId'));
            if ($branch === null) {
                return null;
            }

            return new BitbucketWebhookContext(
                rawEvent: $rawEvent,
                action: 'push',
                branch: $branch,
                repositoryIdentifiers: $repositoryIdentifiers,
                commit: $this->webhookCommitSha($payload, 'changes.0.toHash'),
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

        $branch = $this->webhookString(data_get($pullRequest, 'toRef.displayId'));
        if ($branch === null) {
            return null;
        }

        return new BitbucketWebhookContext(
            rawEvent: $rawEvent,
            action: $action,
            branch: $branch,
            repositoryIdentifiers: $repositoryIdentifiers,
            commit: $this->webhookCommitSha($payload, 'pullRequest.fromRef.latestCommit'),
            skipDeployPr: self::shouldSkipDeployAny([$this->webhookPayloadString($payload, 'pullRequest.title')]),
            pullRequestId: $this->webhookPullRequestId($payload, 'pullRequest.id'),
            pullRequestHtmlUrl: $this->webhookPayloadUrl($payload, 'pullRequest.links.self.0.href'),
            // Data Center pull request events have no top-level repository:
            // the target repository is the one that sends the webhook.
            sourceRepository: data_get($pullRequest, 'fromRef.repository.id'),
            targetRepository: data_get($pullRequest, 'toRef.repository.id'),
            webhookRepository: data_get($pullRequest, 'toRef.repository.id'),
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
