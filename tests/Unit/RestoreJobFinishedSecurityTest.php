<?php

/**
 * Security tests for RestoreJobFinished event to ensure it uses secure path validation.
 */
describe('RestoreJobFinished event security', function () {
    it('validates that safe paths pass validation', function () {
        $validPaths = [
            '/tmp/restore-backup.sql',
            '/tmp/restore-script.sh',
            '/tmp/database-dump-'.uniqid().'.sql',
        ];

        foreach ($validPaths as $path) {
            expect(isSafeTmpPath($path))->toBeTrue();
        }
    });

    it('validates that malicious paths fail validation', function () {
        $maliciousPaths = [
            '/tmp/../etc/passwd',
            '/tmp/foo/../../etc/shadow',
            '/etc/sensitive-file',
            '/var/www/config.php',
            '/tmp/../../../root/.ssh/id_rsa',
        ];

        foreach ($maliciousPaths as $path) {
            expect(isSafeTmpPath($path))->toBeFalse();
        }
    });

    it('rejects URL-encoded path traversal attempts', function () {
        $encodedTraversalPaths = [
            '/tmp/%2e%2e/etc/passwd',
            '/tmp/foo%2f%2e%2e%2f%2e%2e/etc/shadow',
            urlencode('/tmp/../etc/passwd'),
        ];

        foreach ($encodedTraversalPaths as $path) {
            expect(isSafeTmpPath($path))->toBeFalse();
        }
    });

    it('handles edge cases correctly', function () {
        // Too short
        expect(isSafeTmpPath('/tmp'))->toBeFalse();
        expect(isSafeTmpPath('/tmp/'))->toBeFalse();

        // Null/empty
        expect(isSafeTmpPath(null))->toBeFalse();
        expect(isSafeTmpPath(''))->toBeFalse();

        // Null byte injection
        expect(isSafeTmpPath("/tmp/file.sql\0../../etc/passwd"))->toBeFalse();

        // Valid edge cases
        expect(isSafeTmpPath('/tmp/x'))->toBeTrue();
        expect(isSafeTmpPath('/tmp/very/deeply/nested/path/to/file.sql'))->toBeTrue();
    });
});
