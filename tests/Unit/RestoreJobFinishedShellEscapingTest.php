<?php

/**
 * Security tests for shell metacharacter escaping in restore events.
 *
 * These tests verify that escapeshellarg() properly neutralizes shell injection
 * attempts in paths that pass isSafeTmpPath() validation.
 */
describe('Shell metacharacter escaping in restore events', function () {
    it('demonstrates that malicious paths can pass isSafeTmpPath but are neutralized by escapeshellarg', function () {
        // This path passes isSafeTmpPath() validation (it's within /tmp/, no .., no null bytes)
        $maliciousPath = "/tmp/file'; whoami; '";

        // Path validation passes - it's a valid /tmp/ path
        expect(isSafeTmpPath($maliciousPath))->toBeTrue();

        // But when escaped, the shell metacharacters become literal strings
        $escaped = escapeshellarg($maliciousPath);

        // The escaped version wraps in single quotes and escapes internal single quotes
        expect($escaped)->toBe("'/tmp/file'\\''; whoami; '\\'''");

        // Building a command with escaped path is safe
        $command = "rm -f {$escaped}";

        // The command contains the quoted path, not an unquoted injection
        expect($command)->toStartWith("rm -f '");
        expect($command)->toEndWith("'");
    });

    it('escapes paths with semicolon injection attempts', function () {
        $path = '/tmp/backup; rm -rf /; echo';
        expect(isSafeTmpPath($path))->toBeTrue();

        $escaped = escapeshellarg($path);
        expect($escaped)->toBe("'/tmp/backup; rm -rf /; echo'");

        // The semicolons are inside quotes, so they're treated as literals
        $command = "rm -f {$escaped}";
        expect($command)->toBe("rm -f '/tmp/backup; rm -rf /; echo'");
    });

    it('escapes paths with backtick command substitution attempts', function () {
        $path = '/tmp/backup`whoami`.sql';
        expect(isSafeTmpPath($path))->toBeTrue();

        $escaped = escapeshellarg($path);
        expect($escaped)->toBe("'/tmp/backup`whoami`.sql'");

        // Backticks inside single quotes are not executed
        $command = "rm -f {$escaped}";
        expect($command)->toBe("rm -f '/tmp/backup`whoami`.sql'");
    });

    it('escapes paths with $() command substitution attempts', function () {
        $path = '/tmp/backup$(id).sql';
        expect(isSafeTmpPath($path))->toBeTrue();

        $escaped = escapeshellarg($path);
        expect($escaped)->toBe("'/tmp/backup\$(id).sql'");

        // $() inside single quotes is not executed
        $command = "rm -f {$escaped}";
        expect($command)->toBe("rm -f '/tmp/backup\$(id).sql'");
    });

    it('escapes paths with pipe injection attempts', function () {
        $path = '/tmp/backup | cat /etc/passwd';
        expect(isSafeTmpPath($path))->toBeTrue();

        $escaped = escapeshellarg($path);
        expect($escaped)->toBe("'/tmp/backup | cat /etc/passwd'");

        // Pipe inside single quotes is treated as literal
        $command = "rm -f {$escaped}";
        expect($command)->toBe("rm -f '/tmp/backup | cat /etc/passwd'");
    });

    it('escapes paths with newline injection attempts', function () {
        $path = "/tmp/backup\nwhoami";
        expect(isSafeTmpPath($path))->toBeTrue();

        $escaped = escapeshellarg($path);
        // Newline is preserved inside single quotes
        expect($escaped)->toContain("\n");
        expect($escaped)->toStartWith("'");
        expect($escaped)->toEndWith("'");
    });

    it('handles normal paths without issues', function () {
        $normalPaths = [
            '/tmp/restore-backup.sql',
            '/tmp/restore-script.sh',
            '/tmp/database-dump-abc123.sql',
            '/tmp/deeply/nested/path/to/file.sql',
        ];

        foreach ($normalPaths as $path) {
            expect(isSafeTmpPath($path))->toBeTrue();

            $escaped = escapeshellarg($path);
            // Normal paths are just wrapped in single quotes
            expect($escaped)->toBe("'{$path}'");
        }
    });

    it('escapes container names with injection attempts', function () {
        // Container names are not validated by isSafeTmpPath, so escaping is critical
        $maliciousContainer = 'container"; rm -rf /; echo "pwned';
        $escaped = escapeshellarg($maliciousContainer);

        expect($escaped)->toBe("'container\"; rm -rf /; echo \"pwned'");

        // Building a docker command with escaped container is safe
        $command = "docker rm -f {$escaped}";
        expect($command)->toBe("docker rm -f 'container\"; rm -rf /; echo \"pwned'");
    });
});
