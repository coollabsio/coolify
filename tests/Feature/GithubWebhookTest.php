<?php

use App\Models\GithubApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('GitHub Webhook Manual Endpoint', function () {
    test('registry_package event returns graceful response instead of 500', function () {
        $payload = [
            'action' => 'published',
            'registry_package' => [
                'ecosystem' => 'CONTAINER',
                'package_type' => 'CONTAINER',
                'package_version' => [
                    'target_commitish' => 'main',
                    'target_oid' => 'a8ce490f12151ec2194d00d6b3841d3349778ae6',
                    'container_metadata' => [
                        'tag' => ['name' => 'edge'],
                    ],
                ],
            ],
            'repository' => [
                'full_name' => 'test-org/test-repo',
                'default_branch' => 'main',
            ],
        ];

        $response = $this->postJson('/webhooks/source/github/events/manual', $payload, [
            'X-GitHub-Event' => 'registry_package',
            'X-GitHub-Delivery' => 'test-delivery-id',
            'X-Hub-Signature-256' => 'sha256=invalid',
            'Content-Type' => 'application/json',
        ]);

        $response->assertSuccessful();
        $response->assertSee('Nothing to do');
    });

    test('unhandled event type returns graceful response instead of 500', function () {
        $payload = [
            'action' => 'completed',
            'repository' => [
                'full_name' => 'test-org/test-repo',
            ],
        ];

        $response = $this->postJson('/webhooks/source/github/events/manual', $payload, [
            'X-GitHub-Event' => 'check_run',
            'X-GitHub-Delivery' => 'test-delivery-id',
            'X-Hub-Signature-256' => 'sha256=invalid',
            'Content-Type' => 'application/json',
        ]);

        $response->assertSuccessful();
        $response->assertSee('Nothing to do');
    });

    test('push event still works correctly', function () {
        $payload = [
            'ref' => 'refs/heads/main',
            'after' => 'abc123',
            'repository' => [
                'full_name' => 'test-org/test-repo',
            ],
            'commits' => [],
        ];

        $response = $this->postJson('/webhooks/source/github/events/manual', $payload, [
            'X-GitHub-Event' => 'push',
            'X-GitHub-Delivery' => 'test-delivery-id',
            'X-Hub-Signature-256' => 'sha256=invalid',
            'Content-Type' => 'application/json',
        ]);

        // No matching apps, so returns "nothing to do" — not a 500
        $response->assertSuccessful();
    });

    test('ping event returns pong', function () {
        $response = $this->postJson('/webhooks/source/github/events/manual', ['zen' => 'test'], [
            'X-GitHub-Event' => 'ping',
            'X-GitHub-Delivery' => 'test-delivery-id',
            'X-Hub-Signature-256' => 'sha256=invalid',
        ]);

        $response->assertSuccessful();
        $response->assertSee('pong');
    });
});

describe('GitHub Webhook Normal Endpoint', function () {
    test('registry_package event returns graceful response with matching github app', function () {
        $team = Team::factory()->create();
        $user = User::factory()->create();
        $team->members()->attach($user->id, ['role' => 'owner']);

        $githubApp = GithubApp::create([
            'name' => 'Test GitHub App',
            'api_url' => 'https://api.github.com',
            'html_url' => 'https://github.com',
            'custom_user' => 'git',
            'custom_port' => 22,
            'app_id' => 99999,
            'webhook_secret' => 'test-webhook-secret',
            'team_id' => $team->id,
            'is_system_wide' => false,
        ]);

        $payload = json_encode([
            'action' => 'published',
            'registry_package' => [
                'ecosystem' => 'CONTAINER',
                'package_type' => 'CONTAINER',
            ],
            'repository' => [
                'id' => 12345,
                'full_name' => 'test-org/test-repo',
            ],
        ]);

        // Compute valid HMAC so we pass signature check
        $hmac = hash_hmac('sha256', $payload, 'test-webhook-secret');

        $response = $this->call('POST', '/webhooks/source/github/events', [], [], [], [
            'HTTP_X-GitHub-Event' => 'registry_package',
            'HTTP_X-GitHub-Delivery' => 'test-delivery-id',
            'HTTP_X-GitHub-Hook-Installation-Target-Id' => '99999',
            'HTTP_X-Hub-Signature-256' => 'sha256='.$hmac,
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        // Should return "Nothing to do. No id or branch found." instead of 500
        $response->assertSuccessful();
        $response->assertSee('Nothing to do');
    });
});
