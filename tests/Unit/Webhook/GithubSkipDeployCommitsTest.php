<?php

use App\Http\Controllers\Webhook\Github;

describe('Github::allCommitsSkipDeploy', function () {
    test('returns false when commits array is empty', function () {
        expect(Github::allCommitsSkipDeploy([]))->toBeFalse();
    });

    test('returns true when all commits contain [skip ci]', function () {
        $commits = [
            ['message' => 'Update docs [skip ci]'],
            ['message' => 'Fix typo [skip ci]'],
        ];
        expect(Github::allCommitsSkipDeploy($commits))->toBeTrue();
    });

    test('returns true when all commits contain [skip cd]', function () {
        $commits = [
            ['message' => 'Update README [skip cd]'],
        ];
        expect(Github::allCommitsSkipDeploy($commits))->toBeTrue();
    });

    test('returns true when all commits contain either marker (case-insensitive)', function () {
        $commits = [
            ['message' => 'Docs [SKIP CI]'],
            ['message' => 'Changelog [Skip Cd]'],
        ];
        expect(Github::allCommitsSkipDeploy($commits))->toBeTrue();
    });

    test('returns false when at least one commit has no skip marker', function () {
        $commits = [
            ['message' => 'Update docs [skip ci]'],
            ['message' => 'Actual feature change'],
        ];
        expect(Github::allCommitsSkipDeploy($commits))->toBeFalse();
    });

    test('returns false when single commit has no skip marker', function () {
        $commits = [
            ['message' => 'Deploy this please'],
        ];
        expect(Github::allCommitsSkipDeploy($commits))->toBeFalse();
    });

    test('handles commit with missing message key', function () {
        $commits = [
            ['id' => 'abc123'],
        ];
        expect(Github::allCommitsSkipDeploy($commits))->toBeFalse();
    });
});
