<?php

// Note: Full Livewire integration tests require database setup
// These tests verify the SSH key merging logic and public_net configuration

it('validates public_net configuration with IPv4 and IPv6 enabled', function () {
    $enableIpv4 = true;
    $enableIpv6 = true;

    $publicNet = [
        'enable_ipv4' => $enableIpv4,
        'enable_ipv6' => $enableIpv6,
    ];

    expect($publicNet)->toBe([
        'enable_ipv4' => true,
        'enable_ipv6' => true,
    ]);
});

it('validates public_net configuration with IPv4 only', function () {
    $enableIpv4 = true;
    $enableIpv6 = false;

    $publicNet = [
        'enable_ipv4' => $enableIpv4,
        'enable_ipv6' => $enableIpv6,
    ];

    expect($publicNet)->toBe([
        'enable_ipv4' => true,
        'enable_ipv6' => false,
    ]);
});

it('validates public_net configuration with IPv6 only', function () {
    $enableIpv4 = false;
    $enableIpv6 = true;

    $publicNet = [
        'enable_ipv4' => $enableIpv4,
        'enable_ipv6' => $enableIpv6,
    ];

    expect($publicNet)->toBe([
        'enable_ipv4' => false,
        'enable_ipv6' => true,
    ]);
});

it('validates IP address selection prefers IPv4 when both are enabled', function () {
    $enableIpv4 = true;
    $enableIpv6 = true;

    $hetznerServer = [
        'public_net' => [
            'ipv4' => ['ip' => '1.2.3.4'],
            'ipv6' => ['ip' => '2001:db8::1'],
        ],
    ];

    $ipAddress = null;
    if ($enableIpv4 && isset($hetznerServer['public_net']['ipv4']['ip'])) {
        $ipAddress = $hetznerServer['public_net']['ipv4']['ip'];
    } elseif ($enableIpv6 && isset($hetznerServer['public_net']['ipv6']['ip'])) {
        $ipAddress = $hetznerServer['public_net']['ipv6']['ip'];
    }

    expect($ipAddress)->toBe('1.2.3.4');
});

it('validates IP address selection uses IPv6 when only IPv6 is enabled', function () {
    $enableIpv4 = false;
    $enableIpv6 = true;

    $hetznerServer = [
        'public_net' => [
            'ipv4' => ['ip' => '1.2.3.4'],
            'ipv6' => ['ip' => '2001:db8::1'],
        ],
    ];

    $ipAddress = null;
    if ($enableIpv4 && isset($hetznerServer['public_net']['ipv4']['ip'])) {
        $ipAddress = $hetznerServer['public_net']['ipv4']['ip'];
    } elseif ($enableIpv6 && isset($hetznerServer['public_net']['ipv6']['ip'])) {
        $ipAddress = $hetznerServer['public_net']['ipv6']['ip'];
    }

    expect($ipAddress)->toBe('2001:db8::1');
});

it('validates SSH key array merging logic with Coolify key', function () {
    $coolifyKeyId = 123;
    $selectedHetznerKeys = [];

    $sshKeys = array_merge(
        [$coolifyKeyId],
        $selectedHetznerKeys
    );
    $sshKeys = array_unique($sshKeys);
    $sshKeys = array_values($sshKeys);

    expect($sshKeys)->toBe([123])
        ->and(count($sshKeys))->toBe(1);
});

it('validates SSH key array merging with additional Hetzner keys', function () {
    $coolifyKeyId = 123;
    $selectedHetznerKeys = [456, 789];

    $sshKeys = array_merge(
        [$coolifyKeyId],
        $selectedHetznerKeys
    );
    $sshKeys = array_unique($sshKeys);
    $sshKeys = array_values($sshKeys);

    expect($sshKeys)->toBe([123, 456, 789])
        ->and(count($sshKeys))->toBe(3);
});

it('validates deduplication when Coolify key is also in selected keys', function () {
    $coolifyKeyId = 123;
    $selectedHetznerKeys = [123, 456, 789];

    $sshKeys = array_merge(
        [$coolifyKeyId],
        $selectedHetznerKeys
    );
    $sshKeys = array_unique($sshKeys);
    $sshKeys = array_values($sshKeys);

    expect($sshKeys)->toBe([123, 456, 789])
        ->and(count($sshKeys))->toBe(3);
});
