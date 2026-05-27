<?php

use App\Http\Controllers\Webhook\Bitbucket;
use Illuminate\Http\Request;

describe('ParsesBitbucketWebhookEvents', function () {
    test('normalizes data center push event to cloud equivalent', function () {
        expect(Bitbucket::normalizeBitbucketEventKey('repo:refs_changed'))->toBe('repo:push');
        expect(Bitbucket::normalizeBitbucketEventKey('pr:opened'))->toBe('pullrequest:created');
        expect(Bitbucket::normalizeBitbucketEventKey('pr:merged'))->toBe('pullrequest:fulfilled');
    });

    test('parses repo refs changed payload', function () {
        $payload = collect([
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

        $parsed = Bitbucket::parseBitbucketWebhookPayload($payload, 'repo:refs_changed');

        expect($parsed)->not->toBeNull();
        expect($parsed['branch'])->toBe('master');
        expect($parsed['commit'])->toBe('2502c69be7e8570a0fb7a7e2ef1df150433c9549');
        expect($parsed['repository_identifiers'])->toContain('~harrison/fx-data-agents-benchmark-output');
        expect($parsed['repository_identifiers'])->toContain('fx-data-agents-benchmark-output');
    });

    test('reads event key from body when header is missing', function () {
        $request = Request::create('/webhooks/source/bitbucket/events/manual', 'POST', [
            'eventKey' => 'repo:refs_changed',
        ]);

        expect(Bitbucket::bitbucketEventKey($request))->toBe('repo:refs_changed');
    });

    test('ignores non update ref changes', function () {
        $payload = collect([
            'repository' => ['slug' => 'repo', 'project' => ['key' => 'PROJ']],
            'changes' => [[
                'ref' => ['displayId' => 'master'],
                'type' => 'DELETE',
            ]],
        ]);

        expect(Bitbucket::parseBitbucketWebhookPayload($payload, 'repo:refs_changed'))->toBeNull();
    });
});
