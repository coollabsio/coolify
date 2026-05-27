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
