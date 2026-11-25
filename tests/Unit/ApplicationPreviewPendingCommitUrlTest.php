<?php

use App\Models\ApplicationPreview;

const PENDING_COMMIT_URL_SHA = 'abcdef0123456789abcdef0123456789abcdef01';

function previewWithPendingCommit(?string $pullRequestUrl, ?string $commit = PENDING_COMMIT_URL_SHA): ApplicationPreview
{
    return (new ApplicationPreview)->forceFill([
        'pull_request_html_url' => $pullRequestUrl,
        'pending_approval_commit' => $commit,
    ]);
}

it('links to the pending commit inside the pull request', function (string $pullRequestUrl, string $expected) {
    expect(previewWithPendingCommit($pullRequestUrl)->pendingApprovalCommitUrl())->toBe($expected);
})->with([
    'github' => ['https://github.com/acme/app/pull/12', 'https://github.com/acme/app/pull/12/commits/'.PENDING_COMMIT_URL_SHA],
    'gitea' => ['https://git.example.com/acme/app/pulls/12/', 'https://git.example.com/acme/app/pulls/12/commits/'.PENDING_COMMIT_URL_SHA],
    'gitlab' => ['https://gitlab.com/acme/app/-/merge_requests/12', 'https://gitlab.com/acme/app/-/merge_requests/12/diffs?commit_id='.PENDING_COMMIT_URL_SHA],
    'bitbucket keeps the pull request link' => ['https://bitbucket.org/acme/app/pull-requests/12', 'https://bitbucket.org/acme/app/pull-requests/12'],
]);

it('keeps the pull request link when the commit is not a full SHA', function () {
    expect(previewWithPendingCommit('https://github.com/acme/app/pull/12', 'HEAD')->pendingApprovalCommitUrl())
        ->toBe('https://github.com/acme/app/pull/12');
});

it('returns no link without a pull request url', function () {
    expect(previewWithPendingCommit('')->pendingApprovalCommitUrl())->toBeNull();
});
