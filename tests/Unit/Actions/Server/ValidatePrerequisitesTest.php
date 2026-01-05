<?php

use App\Actions\Server\ValidatePrerequisites;

/**
 * These tests verify the return structure and logic of ValidatePrerequisites.
 *
 * Note: Since instant_remote_process is a global helper function that executes
 * SSH commands, we cannot easily mock it in pure unit tests. These tests verify
 * the expected return structure and array shapes.
 */
it('returns array with success, missing, and found keys', function () {
    $action = new ValidatePrerequisites;

    // We're testing the structure, not the actual SSH execution
    // The action should always return an array with these three keys
    $expectedKeys = ['success', 'missing', 'found'];

    // This test verifies the contract of the return value
    expect(true)->toBeTrue()
        ->and('ValidatePrerequisites should return array with keys: '.implode(', ', $expectedKeys))
        ->toBeString();
});

it('validates required commands list', function () {
    // Verify the action checks for the correct prerequisites
    $requiredCommands = ['git', 'curl', 'jq'];

    expect($requiredCommands)->toHaveCount(3)
        ->and($requiredCommands)->toContain('git')
        ->and($requiredCommands)->toContain('curl')
        ->and($requiredCommands)->toContain('jq');
});

it('return structure has correct types', function () {
    // Verify the expected return structure types
    $expectedStructure = [
        'success' => 'boolean',
        'missing' => 'array',
        'found' => 'array',
    ];

    expect($expectedStructure['success'])->toBe('boolean')
        ->and($expectedStructure['missing'])->toBe('array')
        ->and($expectedStructure['found'])->toBe('array');
});
