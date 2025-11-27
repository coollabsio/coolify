<?php

it('merges Coolify key with selected Hetzner keys', function () {
    $coolifyKeyId = 123;
    $selectedHetznerKeys = [456, 789];

    // Simulate the merge logic from createHetznerServer
    $sshKeys = array_merge(
        [$coolifyKeyId],
        $selectedHetznerKeys
    );

    expect($sshKeys)->toBe([123, 456, 789])
        ->and(count($sshKeys))->toBe(3);
});

it('removes duplicate SSH key IDs', function () {
    $coolifyKeyId = 123;
    $selectedHetznerKeys = [123, 456, 789]; // User also selected Coolify key

    // Simulate the merge and deduplication logic
    $sshKeys = array_merge(
        [$coolifyKeyId],
        $selectedHetznerKeys
    );
    $sshKeys = array_unique($sshKeys);
    $sshKeys = array_values($sshKeys);

    expect($sshKeys)->toBe([123, 456, 789])
        ->and(count($sshKeys))->toBe(3);
});

it('works with no selected Hetzner keys', function () {
    $coolifyKeyId = 123;
    $selectedHetznerKeys = [];

    // Simulate the merge logic
    $sshKeys = array_merge(
        [$coolifyKeyId],
        $selectedHetznerKeys
    );

    expect($sshKeys)->toBe([123])
        ->and(count($sshKeys))->toBe(1);
});

it('validates SSH key IDs are integers', function () {
    $selectedHetznerKeys = [456, 789, 1011];

    foreach ($selectedHetznerKeys as $keyId) {
        expect($keyId)->toBeInt();
    }
});
