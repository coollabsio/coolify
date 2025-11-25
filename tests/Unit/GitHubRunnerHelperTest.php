<?php

use App\Models\GitHubRunnerSource;
use App\Models\Team;
use Illuminate\Support\Facades\Http;

test('validateRunnerWebhookSignature validates correct signature', function () {
    $payload = '{"action":"queued"}';
    $secret = 'test-secret';
    $signature = 'sha256='.hash_hmac('sha256', $payload, $secret);

    expect(validateRunnerWebhookSignature($payload, $signature, $secret))->toBeTrue();
});

test('validateRunnerWebhookSignature rejects incorrect signature', function () {
    $payload = '{"action":"queued"}';
    $secret = 'test-secret';
    $wrongSignature = 'sha256='.hash_hmac('sha256', $payload, 'wrong-secret');

    expect(validateRunnerWebhookSignature($payload, $wrongSignature, $secret))->toBeFalse();
});

test('validateRunnerWebhookSignature rejects empty signature', function () {
    $payload = '{"action":"queued"}';
    $secret = 'test-secret';

    expect(validateRunnerWebhookSignature($payload, '', $secret))->toBeFalse();
});

test('validateRunnerWebhookSignature rejects empty secret', function () {
    $payload = '{"action":"queued"}';
    $signature = 'sha256=something';

    expect(validateRunnerWebhookSignature($payload, $signature, ''))->toBeFalse();
});

test('generateRunnerJitConfig returns null when installation token fails', function () {
    $source = Mockery::mock(GitHubRunnerSource::class);
    $source->shouldReceive('generateInstallationToken')->andReturn(null);
    $source->id = 1;

    $result = generateRunnerJitConfig($source, ['coolify-test'], 'runner-name');

    expect($result)->toBeNull();
});

test('generateRunnerJitConfig makes correct API call for organization level', function () {
    Http::fake([
        'api.github.com/orgs/test-org/actions/runners/generate-jitconfig' => Http::response([
            'encoded_jit_config' => 'encoded-config-data',
            'runner' => ['id' => 123],
        ], 200),
    ]);

    $source = Mockery::mock(GitHubRunnerSource::class);
    $source->shouldReceive('generateInstallationToken')->andReturn('test-token');
    $source->id = 1;
    $source->is_organization_level = true;
    $source->organization = 'test-org';

    $result = generateRunnerJitConfig($source, ['coolify-test'], 'runner-name');

    expect($result)->toBeArray()
        ->and($result)->toHaveKey('encoded_jit_config')
        ->and($result['encoded_jit_config'])->toBe('encoded-config-data');
});

test('generateRunnerJitConfig returns null on API failure', function () {
    Http::fake([
        'api.github.com/*' => Http::response([], 500),
    ]);

    $source = Mockery::mock(GitHubRunnerSource::class);
    $source->shouldReceive('generateInstallationToken')->andReturn('test-token');
    $source->id = 1;
    $source->is_organization_level = true;
    $source->organization = 'test-org';

    $result = generateRunnerJitConfig($source, ['coolify-test'], 'runner-name');

    expect($result)->toBeNull();
});

test('generateRunnerRegistrationToken makes correct API call', function () {
    Http::fake([
        'api.github.com/orgs/test-org/actions/runners/registration-token' => Http::response([
            'token' => 'registration-token-123',
            'expires_at' => '2024-01-01T00:00:00Z',
        ], 201),
    ]);

    $source = Mockery::mock(GitHubRunnerSource::class);
    $source->shouldReceive('generateInstallationToken')->andReturn('test-token');
    $source->id = 1;
    $source->is_organization_level = true;
    $source->organization = 'test-org';

    $result = generateRunnerRegistrationToken($source);

    expect($result)->toBe('registration-token-123');
});

test('listGitHubRunners returns empty array on failure', function () {
    Http::fake([
        'api.github.com/*' => Http::response([], 500),
    ]);

    $source = Mockery::mock(GitHubRunnerSource::class);
    $source->shouldReceive('generateInstallationToken')->andReturn('test-token');
    $source->id = 1;
    $source->is_organization_level = true;
    $source->organization = 'test-org';

    $result = listGitHubRunners($source);

    expect($result)->toBeArray()->toBeEmpty();
});

test('listGitHubRunners returns runners array', function () {
    Http::fake([
        'api.github.com/orgs/test-org/actions/runners' => Http::response([
            'runners' => [
                ['id' => 1, 'name' => 'runner-1'],
                ['id' => 2, 'name' => 'runner-2'],
            ],
        ], 200),
    ]);

    $source = Mockery::mock(GitHubRunnerSource::class);
    $source->shouldReceive('generateInstallationToken')->andReturn('test-token');
    $source->id = 1;
    $source->is_organization_level = true;
    $source->organization = 'test-org';

    $result = listGitHubRunners($source);

    expect($result)->toBeArray()
        ->toHaveCount(2)
        ->and($result[0]['name'])->toBe('runner-1');
});

test('removeGitHubRunner returns true on success', function () {
    Http::fake([
        'api.github.com/orgs/test-org/actions/runners/123' => Http::response([], 204),
    ]);

    $source = Mockery::mock(GitHubRunnerSource::class);
    $source->shouldReceive('generateInstallationToken')->andReturn('test-token');
    $source->id = 1;
    $source->is_organization_level = true;
    $source->organization = 'test-org';

    $result = removeGitHubRunner($source, '123');

    expect($result)->toBeTrue();
});

test('removeGitHubRunner returns false on failure', function () {
    Http::fake([
        'api.github.com/*' => Http::response([], 500),
    ]);

    $source = Mockery::mock(GitHubRunnerSource::class);
    $source->shouldReceive('generateInstallationToken')->andReturn('test-token');
    $source->id = 1;
    $source->is_organization_level = true;
    $source->organization = 'test-org';

    $result = removeGitHubRunner($source, '123');

    expect($result)->toBeFalse();
});
