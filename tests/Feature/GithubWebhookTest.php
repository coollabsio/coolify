<?php

describe('GitHub Manual Webhook', function () {
    test('ping event returns pong', function () {
        $response = $this->postJson('/webhooks/source/github/events/manual', [], [
            'X-GitHub-Event' => 'ping',
        ]);

        $response->assertOk();
        $response->assertSee('pong');
    });

    test('unsupported event type returns graceful response instead of 500', function () {
        $payload = [
            'action' => 'published',
            'registry_package' => [
                'ecosystem' => 'CONTAINER',
                'package_type' => 'CONTAINER',
                'package_version' => [
                    'target_commitish' => 'main',
                ],
            ],
            'repository' => [
                'full_name' => 'test-org/test-repo',
                'default_branch' => 'main',
            ],
        ];

        $response = $this->postJson('/webhooks/source/github/events/manual', $payload, [
            'X-GitHub-Event' => 'registry_package',
            'X-Hub-Signature-256' => 'sha256=fake',
        ]);

        $response->assertOk();
        $response->assertSee('not supported');
    });

    test('unknown event type returns graceful response', function () {
        $response = $this->postJson('/webhooks/source/github/events/manual', ['foo' => 'bar'], [
            'X-GitHub-Event' => 'some_unknown_event',
            'X-Hub-Signature-256' => 'sha256=fake',
        ]);

        $response->assertOk();
        $response->assertSee('not supported');
    });
});

describe('GitHub Normal Webhook', function () {
    test('unsupported event type returns graceful response instead of 500', function () {
        $payload = [
            'action' => 'published',
            'registry_package' => [
                'ecosystem' => 'CONTAINER',
            ],
            'repository' => [
                'full_name' => 'test-org/test-repo',
            ],
        ];

        $response = $this->postJson('/webhooks/source/github/events', $payload, [
            'X-GitHub-Event' => 'registry_package',
            'X-GitHub-Hook-Installation-Target-Id' => '12345',
            'X-Hub-Signature-256' => 'sha256=fake',
        ]);

        // Should not be a 500 error - either 200 with "not supported" or "No GitHub App found"
        $response->assertOk();
    });
});
