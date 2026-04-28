<?php

use App\Http\Controllers\Webhook\Github;

describe('Github::shouldSkipDeploy', function () {
    test('returns false when messages array is empty', function () {
        expect(Github::shouldSkipDeploy([]))->toBeFalse();
    });

    test('returns false when only nulls or empty strings are provided', function () {
        expect(Github::shouldSkipDeploy([null, '', null]))->toBeFalse();
    });

    test('returns true when all messages contain [skip ci]', function () {
        $messages = [
            'Update docs [skip ci]',
            'Fix typo [skip ci]',
        ];
        expect(Github::shouldSkipDeploy($messages))->toBeTrue();
    });

    test('returns true when single message contains [skip cd]', function () {
        expect(Github::shouldSkipDeploy(['Update README [skip cd]']))->toBeTrue();
    });

    test('returns true with mixed [skip ci] and [skip cd] (case-insensitive)', function () {
        $messages = [
            'Docs [SKIP CI]',
            'Changelog [Skip Cd]',
        ];
        expect(Github::shouldSkipDeploy($messages))->toBeTrue();
    });

    test('returns false when at least one message has no skip marker', function () {
        $messages = [
            'Update docs [skip ci]',
            'Actual feature change',
        ];
        expect(Github::shouldSkipDeploy($messages))->toBeFalse();
    });

    test('returns false when single message has no skip marker', function () {
        expect(Github::shouldSkipDeploy(['Deploy this please']))->toBeFalse();
    });

    test('null entries are filtered before evaluation', function () {
        $messages = [
            null,
            'Docs [skip ci]',
            null,
        ];
        expect(Github::shouldSkipDeploy($messages))->toBeTrue();
    });

    test('matches PR title scenario (single string)', function () {
        expect(Github::shouldSkipDeploy(['chore: update readme [skip ci]']))->toBeTrue();
        expect(Github::shouldSkipDeploy(['feat: real change']))->toBeFalse();
        expect(Github::shouldSkipDeploy([null]))->toBeFalse();
    });
});
