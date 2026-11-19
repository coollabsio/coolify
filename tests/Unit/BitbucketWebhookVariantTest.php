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
                        ['href' => 'ssh://git@code.fineres.com:7999/~harrison/fx-data-agents-benchmark-output.git'],
                    ],
                ],
            ],
            'changes' => [[
                'ref' => ['displayId' => 'master'],
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
