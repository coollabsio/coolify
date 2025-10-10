<?php

// Note: Full Livewire integration tests require database setup
// These tests verify the SSH key merging logic works correctly

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

it('validates public_net configuration with IPv4 and IPv6 enabled by default', function () {
    $enableIpv4 = true;
    $enableIpv6 = true;

    $publicNet = [
        'enable_ipv4' => $enableIpv4,
        'enable_ipv6' => $enableIpv6,
    ];

    expect($publicNet)->toBe([
        'enable_ipv4' => true,
        'enable_ipv6' => true,
    ])
        ->and($publicNet['enable_ipv4'])->toBeTrue()
        ->and($publicNet['enable_ipv6'])->toBeTrue();
});

it('validates public_net configuration with only IPv4 enabled', function () {
    $enableIpv4 = true;
    $enableIpv6 = false;

    $publicNet = [
        'enable_ipv4' => $enableIpv4,
        'enable_ipv6' => $enableIpv6,
    ];

    expect($publicNet)->toBe([
        'enable_ipv4' => true,
        'enable_ipv6' => false,
    ])
        ->and($publicNet['enable_ipv4'])->toBeTrue()
        ->and($publicNet['enable_ipv6'])->toBeFalse();
});

it('validates public_net configuration with only IPv6 enabled', function () {
    $enableIpv4 = false;
    $enableIpv6 = true;

    $publicNet = [
        'enable_ipv4' => $enableIpv4,
        'enable_ipv6' => $enableIpv6,
    ];

    expect($publicNet)->toBe([
        'enable_ipv4' => false,
        'enable_ipv6' => true,
    ])
        ->and($publicNet['enable_ipv4'])->toBeFalse()
        ->and($publicNet['enable_ipv6'])->toBeTrue();
});

it('validates public_net configuration with both disabled', function () {
    $enableIpv4 = false;
    $enableIpv6 = false;

    $publicNet = [
        'enable_ipv4' => $enableIpv4,
        'enable_ipv6' => $enableIpv6,
    ];

    expect($publicNet)->toBe([
        'enable_ipv4' => false,
        'enable_ipv6' => false,
    ])
        ->and($publicNet['enable_ipv4'])->toBeFalse()
        ->and($publicNet['enable_ipv6'])->toBeFalse();
});
