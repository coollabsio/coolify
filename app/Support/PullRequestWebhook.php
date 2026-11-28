<?php

namespace App\Support;

/**
 * A pull request event of a Git provider, read for the previews of cluster applications
 * (decision 0025). Each provider maps its own action names to deploy or close with ACTIONS,
 * the v4 events.
 */
final readonly class PullRequestWebhook
{
    /** @var array<'github'|'gitlab'|'gitea'|'bitbucket', array{deploy: list<string>, close: list<string>}> */
    public const ACTIONS = [
        'github' => ['deploy' => ['opened', 'synchronize', 'reopened'], 'close' => ['closed']],
        'gitlab' => ['deploy' => ['open', 'opened', 'synchronize', 'reopened', 'reopen', 'update'], 'close' => ['closed', 'close', 'merge']],
        'gitea' => ['deploy' => ['opened', 'synchronized', 'reopened'], 'close' => ['closed']],
        'bitbucket' => ['deploy' => ['pullrequest:created', 'pullrequest:updated'], 'close' => ['pullrequest:rejected', 'pullrequest:fulfilled']],
    ];

    /**
     * @param  string  $ref  The ref with the head commit in the base repository, or the source branch of Bitbucket.
     * @param  ?string  $refusal  Why the provider cannot deploy this pull request, like a Bitbucket fork.
     * @param  ?string  $beforeSha  The head before a GitHub `synchronize`, for the changed files (decision 0033).
     */
    public function __construct(
        public bool $closes,
        public int $number,
        public ?string $baseBranch,
        public ?string $commitSha,
        public string $ref,
        public ?string $url,
        public bool $fromFork,
        public bool $skipDeploy,
        public ?string $refusal = null,
        public ?string $action = null,
        public ?string $beforeSha = null,
        public ?string $afterSha = null,
    ) {}

    /**
     * Null when the action neither deploys nor closes a preview.
     *
     * @param  'github'|'gitlab'|'gitea'|'bitbucket'  $provider
     */
    public static function forAction(string $provider, ?string $action, int $number, ?string $baseBranch, ?string $commitSha, string $ref, ?string $url, bool $fromFork, bool $skipDeploy, ?string $refusal = null, ?string $beforeSha = null, ?string $afterSha = null): ?self
    {
        $closes = in_array($action, self::ACTIONS[$provider]['close'], true);
        if (! $closes && ! in_array($action, self::ACTIONS[$provider]['deploy'], true)) {
            return null;
        }

        return new self($closes, $number, $baseBranch, $commitSha, $ref, $url, $fromFork, $skipDeploy, $refusal, $action, $beforeSha, $afterSha);
    }
}
