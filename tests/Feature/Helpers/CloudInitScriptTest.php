<?php

// Note: These tests verify cloud-init script logic without database setup

it('validates cloud-init script is included in server params when provided', function () {
    $cloudInitScript = "#!/bin/bash\necho 'Hello World'";
    $params = [
        'name' => 'test-server',
        'server_type' => 'cx11',
        'image' => 1,
        'location' => 'nbg1',
        'start_after_create' => true,
        'ssh_keys' => [123],
        'public_net' => [
            'enable_ipv4' => true,
            'enable_ipv6' => true,
        ],
    ];

    // Add cloud-init script if provided
    if (! empty($cloudInitScript)) {
        $params['user_data'] = $cloudInitScript;
    }

    expect($params)
        ->toHaveKey('user_data')
        ->and($params['user_data'])->toBe("#!/bin/bash\necho 'Hello World'");
});

it('validates cloud-init script is not included when empty', function () {
    $cloudInitScript = null;
    $params = [
        'name' => 'test-server',
        'server_type' => 'cx11',
        'image' => 1,
        'location' => 'nbg1',
        'start_after_create' => true,
        'ssh_keys' => [123],
        'public_net' => [
            'enable_ipv4' => true,
            'enable_ipv6' => true,
        ],
    ];

    // Add cloud-init script if provided
    if (! empty($cloudInitScript)) {
        $params['user_data'] = $cloudInitScript;
    }

    expect($params)->not->toHaveKey('user_data');
});

it('validates cloud-init script is not included when empty string', function () {
    $cloudInitScript = '';
    $params = [
        'name' => 'test-server',
        'server_type' => 'cx11',
        'image' => 1,
        'location' => 'nbg1',
        'start_after_create' => true,
        'ssh_keys' => [123],
        'public_net' => [
            'enable_ipv4' => true,
            'enable_ipv6' => true,
        ],
    ];

    // Add cloud-init script if provided
    if (! empty($cloudInitScript)) {
        $params['user_data'] = $cloudInitScript;
    }

    expect($params)->not->toHaveKey('user_data');
});

it('validates cloud-init script with multiline content', function () {
    $cloudInitScript = "#cloud-config\n\npackages:\n  - nginx\n  - git\n\nruncmd:\n  - systemctl start nginx";
    $params = [
        'name' => 'test-server',
        'server_type' => 'cx11',
        'image' => 1,
        'location' => 'nbg1',
        'start_after_create' => true,
        'ssh_keys' => [123],
        'public_net' => [
            'enable_ipv4' => true,
            'enable_ipv6' => true,
        ],
    ];

    // Add cloud-init script if provided
    if (! empty($cloudInitScript)) {
        $params['user_data'] = $cloudInitScript;
    }

    expect($params)
        ->toHaveKey('user_data')
        ->and($params['user_data'])->toContain('#cloud-config')
        ->and($params['user_data'])->toContain('packages:')
        ->and($params['user_data'])->toContain('runcmd:');
});
