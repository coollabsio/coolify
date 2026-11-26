<?php

namespace App\Http\Controllers\Webhook\Bitbucket;

use App\Http\Controllers\Webhook\Concerns\DetectsSkipDeployCommits;
use App\Http\Controllers\Webhook\Concerns\ReadsWebhookPushPayload;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BitbucketCloudWebhookVariant implements BitbucketWebhookVariant
{
    use DetectsSkipDeployCommits;
    use ReadsWebhookPushPayload;

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
            // A push of several branches sends one change for each branch. A
            // deleted branch has no "new" state and is ignored. Bitbucket sends
            // up to 5 commits for each change, so a larger push is checked for
            // [skip ci] / [skip cd] only on the 5 commits that it sends.
            $changes = data_get($payload, 'push.changes');
            $pushedBranches = [];
            foreach (is_array($changes) ? $changes : [] as $index => $change) {
                $branch = $this->webhookString(data_get($change, 'new.name'));
                if ($branch === null || isset($pushedBranches[$branch])) {
                    continue;
                }
                $pushedBranches[$branch] = [
                    'commit' => $this->webhookCommitSha($payload, "push.changes.{$index}.new.target.hash"),
                    'skip_deploy' => self::shouldSkipDeploy($this->webhookPushCommitMessages($change)),
                ];
            }

            return BitbucketWebhookContext::push($rawEvent, $repositoryIdentifiers, $pushedBranches);
        }

        if (in_array($rawEvent, ['pullrequest:created', 'pullrequest:updated', 'pullrequest:rejected', 'pullrequest:fulfilled'], true)) {
            $pullRequest = data_get($payload, 'pullrequest');
            if (! is_array($pullRequest)) {
                return null;
            }

            $branch = $this->webhookString(data_get($pullRequest, 'destination.branch.name'));
            if ($branch === null) {
                return null;
            }

            $action = in_array($rawEvent, ['pullrequest:rejected', 'pullrequest:fulfilled'], true)
                ? 'preview_close'
                : 'preview_deploy';

            return new BitbucketWebhookContext(
                rawEvent: $rawEvent,
                action: $action,
                branch: $branch,
                repositoryIdentifiers: $repositoryIdentifiers,
                commit: $this->webhookCommitSha($payload, 'pullrequest.source.commit.hash'),
                skipDeployPr: self::shouldSkipDeployAny([$this->webhookPayloadString($payload, 'pullrequest.title')]),
                pullRequestId: $this->webhookPullRequestId($payload, 'pullrequest.id'),
                pullRequestHtmlUrl: $this->webhookPayloadUrl($payload, 'pullrequest.links.html.href'),
                sourceRepository: data_get($pullRequest, 'source.repository.uuid'),
                targetRepository: data_get($pullRequest, 'destination.repository.uuid'),
                webhookRepository: data_get($payload, 'repository.uuid'),
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
