<?php

use App\Http\Controllers\Webhook\Concerns\DetectsSkipDeployCommits;

$harness = new class
{
    use DetectsSkipDeployCommits;
};

$harnessClass = get_class($harness);

describe('shouldSkipDeploy (all-must-match)', function () use ($harnessClass) {
    test('returns false when messages array is empty', function () use ($harnessClass) {
        expect($harnessClass::shouldSkipDeploy([]))->toBeFalse();
    });

    test('returns false when only nulls or empty strings are provided', function () use ($harnessClass) {
        expect($harnessClass::shouldSkipDeploy([null, '', null]))->toBeFalse();
    });

    test('returns true when all messages contain [skip ci]', function () use ($harnessClass) {
        $messages = [
            'Update docs [skip ci]',
            'Fix typo [skip ci]',
        ];
        expect($harnessClass::shouldSkipDeploy($messages))->toBeTrue();
    });

    test('returns true when single message contains [skip cd]', function () use ($harnessClass) {
        expect($harnessClass::shouldSkipDeploy(['Update README [skip cd]']))->toBeTrue();
    });

    test('returns true with mixed [skip ci] and [skip cd] (case-insensitive)', function () use ($harnessClass) {
        $messages = [
            'Docs [SKIP CI]',
            'Changelog [Skip Cd]',
        ];
        expect($harnessClass::shouldSkipDeploy($messages))->toBeTrue();
    });

    test('returns false when at least one message has no skip marker', function () use ($harnessClass) {
        $messages = [
            'Update docs [skip ci]',
            'Actual feature change',
        ];
        expect($harnessClass::shouldSkipDeploy($messages))->toBeFalse();
    });

    test('returns false when single message has no skip marker', function () use ($harnessClass) {
        expect($harnessClass::shouldSkipDeploy(['Deploy this please']))->toBeFalse();
    });

    test('null entries are filtered before evaluation', function () use ($harnessClass) {
        $messages = [
            null,
            'Docs [skip ci]',
            null,
        ];
        expect($harnessClass::shouldSkipDeploy($messages))->toBeTrue();
    });

    test('matches PR title scenario (single string)', function () use ($harnessClass) {
        expect($harnessClass::shouldSkipDeploy(['chore: update readme [skip ci]']))->toBeTrue();
        expect($harnessClass::shouldSkipDeploy(['feat: real change']))->toBeFalse();
        expect($harnessClass::shouldSkipDeploy([null]))->toBeFalse();
    });
});

describe('shouldSkipDeployAny (any-marker)', function () use ($harnessClass) {
    test('returns false when messages array is empty', function () use ($harnessClass) {
        expect($harnessClass::shouldSkipDeployAny([]))->toBeFalse();
    });

    test('returns false when only nulls or empty strings are provided', function () use ($harnessClass) {
        expect($harnessClass::shouldSkipDeployAny([null, '', null]))->toBeFalse();
    });

    test('returns true when any one message contains [skip ci]', function () use ($harnessClass) {
        $messages = [
            'Real feature change',
            'docs: update readme [skip ci]',
        ];
        expect($harnessClass::shouldSkipDeployAny($messages))->toBeTrue();
    });

    test('returns true when any one message contains [skip cd]', function () use ($harnessClass) {
        expect($harnessClass::shouldSkipDeployAny(['feature change', 'chore [skip cd]']))->toBeTrue();
    });

    test('returns true case-insensitively', function () use ($harnessClass) {
        expect($harnessClass::shouldSkipDeployAny(['feat: docs [SKIP CI]']))->toBeTrue();
        expect($harnessClass::shouldSkipDeployAny(['feat: docs [Skip Cd]']))->toBeTrue();
    });

    test('returns false when no message contains a skip marker', function () use ($harnessClass) {
        $messages = [
            'feat: add new endpoint',
            'fix: handle edge case',
        ];
        expect($harnessClass::shouldSkipDeployAny($messages))->toBeFalse();
    });

    test('null and empty entries are skipped, real markers still match', function () use ($harnessClass) {
        expect($harnessClass::shouldSkipDeployAny([null, '', 'docs [skip ci]', null]))->toBeTrue();
        expect($harnessClass::shouldSkipDeployAny([null, '', null]))->toBeFalse();
    });

    test('PR title alone with skip marker triggers skip', function () use ($harnessClass) {
        expect($harnessClass::shouldSkipDeployAny(['chore: update readme [skip ci]']))->toBeTrue();
    });

    test('PR title without skip marker but commit message with skip marker triggers skip', function () use ($harnessClass) {
        expect($harnessClass::shouldSkipDeployAny(['feat: real change', 'wip [skip cd]']))->toBeTrue();
    });
});
