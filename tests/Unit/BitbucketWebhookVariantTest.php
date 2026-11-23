<?php

use App\Http\Controllers\Webhook\Bitbucket\BitbucketCloudWebhookVariant;
use App\Http\Controllers\Webhook\Bitbucket\BitbucketDataCenterWebhookVariant;
use App\Http\Controllers\Webhook\Bitbucket\BitbucketWebhookVariantRegistry;
use Illuminate\Http\Request;

describe('BitbucketWebhookVariantRegistry', function () {
    test('resolves data center variant for repo refs changed payload', function () {
        $request = Request::create('/webhooks/source/bitbucket/events/manual', 'POST', [
            'eventKey' => 'repo:refs_changed',
            'repository' => ['slug' => 'repo', 'project' => ['key' => 'PROJ']],
            'changes' => [[
                'ref' => ['displayId' => 'master'],
                'toHash' => 'abc',
                'type' => 'UPDATE',
            ]],
        ]);

        $variant = (new BitbucketWebhookVariantRegistry)->resolve($request);

        expect($variant)->toBeInstanceOf(BitbucketDataCenterWebhookVariant::class);
    });

    test('resolves cloud variant for repo push payload', function () {
        $request = Request::create('/webhooks/source/bitbucket/events/manual', 'POST', [
            'repository' => ['full_name' => 'org/repo'],
            'push' => ['changes' => [['new' => ['name' => 'main', 'target' => ['hash' => 'abc']]]]],
        ], [], [], [
            'HTTP_X-Event-Key' => 'repo:push',
        ]);

        $variant = (new BitbucketWebhookVariantRegistry)->resolve($request);

        expect($variant)->toBeInstanceOf(BitbucketCloudWebhookVariant::class);
    });
});

describe('BitbucketDataCenterWebhookVariant', function () {
    test('parses repo refs changed payload', function () {
        $request = Request::create('/webhooks/source/bitbucket/events/manual', 'POST', [
            'eventKey' => 'repo:refs_changed',
            'repository' => [
                'slug' => 'fx-data-agents-benchmark-output',
                'project' => ['key' => '~HARRISON'],
                'links' => [
                    'clone' => [
                        ['href' => 'ssh://git@bitbucket.example.com:7999/~harrison/fx-data-agents-benchmark-output.git'],
                    ],
                ],
            ],
            'changes' => [[
                'ref' => ['displayId' => 'master', 'type' => 'BRANCH'],
                'toHash' => '2502c69be7e8570a0fb7a7e2ef1df150433c9549',
                'type' => 'UPDATE',
            ]],
        ]);

        $context = (new BitbucketDataCenterWebhookVariant)->parse($request);

        expect($context)->not->toBeNull();
        expect($context->action)->toBe('push');
        expect($context->branch)->toBe('master');
        expect($context->repositoryIdentifiers)->toContain('~harrison/fx-data-agents-benchmark-output');
    });
});

describe('BitbucketDataCenterWebhookVariant pull requests', function () {
    test('parses a fork pull request with its source and target repositories', function () {
        $request = Request::create('/webhooks/source/bitbucket/events/manual', 'POST', [
            'eventKey' => 'pr:opened',
            'pullRequest' => [
                'id' => 12,
                'title' => 'Add feature',
                'fromRef' => [
                    'displayId' => 'feature',
                    'latestCommit' => '2502c69be7e8570a0fb7a7e2ef1df150433c9549',
                    'repository' => ['id' => 2, 'slug' => 'repo', 'project' => ['key' => '~FORKER']],
                ],
                'toRef' => [
                    'displayId' => 'main',
                    'repository' => ['id' => 1, 'slug' => 'repo', 'project' => ['key' => 'PROJ']],
                ],
                'links' => ['self' => [['href' => 'https://bitbucket.example.com/projects/PROJ/repos/repo/pull-requests/12']]],
            ],
        ]);

        $context = (new BitbucketDataCenterWebhookVariant)->parse($request);

        expect($context->action)->toBe('preview_deploy')
            ->and($context->branch)->toBe('main')
            ->and($context->pullRequestId)->toBe(12)
            ->and($context->repositoryIdentifiers)->toContain('PROJ/repo')
            ->and($context->sourceRepository)->toBe(2)
            ->and($context->targetRepository)->toBe(1)
            ->and($context->webhookRepository)->toBe(1);
    });
});

describe('BitbucketDataCenterWebhookVariant pushes', function () {
    function dataCenterPushRequest(array $changes, array $commits = [], array $repository = ['slug' => 'repo', 'project' => ['key' => 'PROJ']]): Request
    {
        return Request::create('/webhooks/source/bitbucket/events/manual', 'POST', [
            'eventKey' => 'repo:refs_changed',
            'repository' => $repository,
            'changes' => $changes,
            'commits' => $commits,
        ]);
    }

    test('ignores tag pushes and branch deletions', function (array $change) {
        expect((new BitbucketDataCenterWebhookVariant)->parse(dataCenterPushRequest([$change])))->toBeNull();
    })->with([
        'tag' => [['ref' => ['displayId' => 'main', 'type' => 'TAG'], 'toHash' => str_repeat('a', 40), 'type' => 'UPDATE']],
        'branch deletion' => [['ref' => ['displayId' => 'main', 'type' => 'BRANCH'], 'toHash' => str_repeat('0', 40), 'type' => 'DELETE']],
    ]);

    test('reads every created or updated branch change', function () {
        $context = (new BitbucketDataCenterWebhookVariant)->parse(dataCenterPushRequest([
            ['ref' => ['displayId' => 'v1.0', 'type' => 'TAG'], 'toHash' => str_repeat('a', 40), 'type' => 'ADD'],
            ['ref' => ['displayId' => 'main', 'type' => 'BRANCH'], 'toHash' => str_repeat('b', 40), 'type' => 'ADD'],
            ['ref' => ['displayId' => 'old', 'type' => 'BRANCH'], 'toHash' => str_repeat('0', 40), 'type' => 'DELETE'],
            ['ref' => ['displayId' => 'develop', 'type' => 'BRANCH'], 'toHash' => str_repeat('c', 40), 'type' => 'UPDATE'],
        ]));

        expect($context->branch)->toBe('main')
            ->and($context->commit)->toBe(str_repeat('b', 40))
            ->and($context->pushedBranches)->toBe([
                'main' => ['commit' => str_repeat('b', 40), 'skip_deploy' => false],
                'develop' => ['commit' => str_repeat('c', 40), 'skip_deploy' => false],
            ]);
    });

    test('skips the deployment when every commit asks for it', function (array $commits, bool $skip) {
        $context = (new BitbucketDataCenterWebhookVariant)->parse(dataCenterPushRequest(
            [['ref' => ['displayId' => 'main', 'type' => 'BRANCH'], 'toHash' => str_repeat('a', 40), 'type' => 'UPDATE']],
            $commits,
        ));

        expect($context->pushedBranches['main']['skip_deploy'])->toBe($skip);
    })->with([
        'all skip' => [[['message' => 'docs [skip ci]'], ['message' => 'chore [skip cd]']], true],
        'one deploys' => [[['message' => 'docs [skip ci]'], ['message' => 'Fix the build']], false],
        'no commits' => [[], false],
    ]);

    test('limits the number of repository identifiers', function () {
        $clones = array_map(fn (int $i): array => ['href' => "https://bitbucket.example.com/scm/p{$i}/r{$i}.git"], range(1, 50));

        $context = (new BitbucketDataCenterWebhookVariant)->parse(dataCenterPushRequest(
            [['ref' => ['displayId' => 'main', 'type' => 'BRANCH'], 'toHash' => str_repeat('a', 40), 'type' => 'UPDATE']],
            repository: ['slug' => 'repo', 'project' => ['key' => 'PROJ'], 'links' => ['clone' => $clones]],
        ));

        expect($context->repositoryIdentifiers)->toHaveCount(10)
            ->and($context->repositoryIdentifiers[0])->toBe('PROJ/repo');
    });
});

test('uses only the full name of a bitbucket cloud repository', function () {
    $request = Request::create('/webhooks/source/bitbucket/events/manual', 'POST', [
        'repository' => [
            'full_name' => 'workspace/repo',
            'slug' => 'repo',
            'project' => ['key' => 'PROJ'],
            'links' => ['clone' => [['href' => 'https://bitbucket.org/other/repo.git']]],
        ],
        'push' => ['changes' => [['new' => ['name' => 'main', 'target' => ['hash' => str_repeat('a', 40)]]]]],
    ], [], [], ['HTTP_X-Event-Key' => 'repo:push']);

    $context = (new BitbucketCloudWebhookVariant)->parse($request);

    expect($context->repositoryIdentifiers)->toBe(['workspace/repo']);
});
