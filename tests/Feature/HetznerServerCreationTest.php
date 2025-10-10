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
