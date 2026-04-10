<?php

use App\Models\Application;
use App\Models\ApplicationSetting;

/**
 * Security tests for git ref validation (GHSA-mw5w-2vvh-mgf4).
 *
 * Ensures that git_commit_sha and related inputs are validated
 * to prevent OS command injection via shell metacharacters.
 */
describe('validateGitRef', function () {
    test('accepts valid hex commit SHAs', function () {
        expect(validateGitRef('abc123def456'))->toBe('abc123def456');
        expect(validateGitRef('a3e59e5c9'))->toBe('a3e59e5c9');
        expect(validateGitRef('abc123def456abc123def456abc123def456abc123'))->toBe('abc123def456abc123def456abc123def456abc123');
    });

    test('accepts HEAD', function () {
        expect(validateGitRef('HEAD'))->toBe('HEAD');
    });

    test('accepts empty string', function () {
        expect(validateGitRef(''))->toBe('');
    });

    test('accepts branch and tag names', function () {
        expect(validateGitRef('main'))->toBe('main');
        expect(validateGitRef('feature/my-branch'))->toBe('feature/my-branch');
        expect(validateGitRef('v1.2.3'))->toBe('v1.2.3');
        expect(validateGitRef('release-2.0'))->toBe('release-2.0');
        expect(validateGitRef('my_branch'))->toBe('my_branch');
    });

    test('trims whitespace', function () {
        expect(validateGitRef('  abc123  '))->toBe('abc123');
    });

    test('rejects single quote injection', function () {
        expect(fn () => validateGitRef("HEAD'; id >/tmp/poc; #"))
            ->toThrow(Exception::class);
    });

    test('rejects semicolon command separator', function () {
        expect(fn () => validateGitRef('abc123; rm -rf /'))
            ->toThrow(Exception::class);
    });

    test('rejects command substitution with $()', function () {
        expect(fn () => validateGitRef('$(whoami)'))
            ->toThrow(Exception::class);
    });

    test('rejects backtick command substitution', function () {
        expect(fn () => validateGitRef('`whoami`'))
            ->toThrow(Exception::class);
    });

    test('rejects pipe operator', function () {
        expect(fn () => validateGitRef('abc | cat /etc/passwd'))
            ->toThrow(Exception::class);
    });

    test('rejects ampersand operator', function () {
        expect(fn () => validateGitRef('abc & whoami'))
            ->toThrow(Exception::class);
    });

    test('rejects hash comment injection', function () {
        expect(fn () => validateGitRef('abc #'))
            ->toThrow(Exception::class);
    });

    test('rejects newline injection', function () {
        expect(fn () => validateGitRef("abc\nwhoami"))
            ->toThrow(Exception::class);
    });

    test('rejects redirect operators', function () {
        expect(fn () => validateGitRef('abc > /tmp/out'))
            ->toThrow(Exception::class);
    });

    test('rejects hyphen-prefixed input (git flag injection)', function () {
        expect(fn () => validateGitRef('--upload-pack=malicious'))
            ->toThrow(Exception::class);
    });

    test('rejects the exact PoC payload from advisory', function () {
        expect(fn () => validateGitRef("HEAD'; whoami >/tmp/coolify_poc_git; #"))
            ->toThrow(Exception::class);
    });
});

describe('executeInDocker git log escaping', function () {
    test('git log command escapes commit SHA to prevent injection', function () {
        $maliciousCommit = "HEAD'; id; #";
        $command = 'cd /workdir && git log -1 '.escapeshellarg($maliciousCommit).' --pretty=%B';
        $result = executeInDocker('test-container', $command);

        // The malicious payload must not be able to break out of quoting
        expect($result)->not->toContain('id;');
        expect($result)->toContain("'HEAD'\\''");
    });
});

describe('buildGitCheckoutCommand escaping', function () {
    test('checkout command escapes target to prevent injection', function () {
        $app = new Application;
        $app->fill(['uuid' => 'test-uuid']);

        $settings = new ApplicationSetting;
        $settings->is_git_submodules_enabled = false;
        $app->setRelation('settings', $settings);

        $method = new ReflectionMethod($app, 'buildGitCheckoutCommand');

        $result = $method->invoke($app, 'abc123');
        expect($result)->toContain("git checkout 'abc123'");

        $result = $method->invoke($app, "abc'; id; #");
        expect($result)->not->toContain('id;');
        expect($result)->toContain("git checkout 'abc'");
    });
});
