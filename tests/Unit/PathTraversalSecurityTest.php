<?php

/**
 * Security tests for isSafeTmpPath() function to prevent path traversal attacks.
 */
describe('isSafeTmpPath() security validation', function () {
    it('rejects null and empty paths', function () {
        expect(isSafeTmpPath(null))->toBeFalse();
        expect(isSafeTmpPath(''))->toBeFalse();
        expect(isSafeTmpPath('   '))->toBeFalse();
    });

    it('rejects paths shorter than minimum length', function () {
        expect(isSafeTmpPath('/tmp'))->toBeFalse();
        expect(isSafeTmpPath('/tmp/'))->toBeFalse();
        expect(isSafeTmpPath('/tmp/a'))->toBeTrue(); // 6 chars exactly, should pass
    });

    it('accepts valid /tmp/ paths', function () {
        expect(isSafeTmpPath('/tmp/file.txt'))->toBeTrue();
        expect(isSafeTmpPath('/tmp/backup.sql'))->toBeTrue();
        expect(isSafeTmpPath('/tmp/subdir/file.txt'))->toBeTrue();
        expect(isSafeTmpPath('/tmp/very/deep/nested/path/file.sql'))->toBeTrue();
    });

    it('rejects obvious path traversal attempts with ..', function () {
        expect(isSafeTmpPath('/tmp/../etc/passwd'))->toBeFalse();
        expect(isSafeTmpPath('/tmp/foo/../etc/passwd'))->toBeFalse();
        expect(isSafeTmpPath('/tmp/foo/bar/../../etc/passwd'))->toBeFalse();
        expect(isSafeTmpPath('/tmp/foo/../../../etc/passwd'))->toBeFalse();
    });

    it('rejects paths that do not start with /tmp/', function () {
        expect(isSafeTmpPath('/etc/passwd'))->toBeFalse();
        expect(isSafeTmpPath('/home/user/file.txt'))->toBeFalse();
        expect(isSafeTmpPath('/var/log/app.log'))->toBeFalse();
        expect(isSafeTmpPath('tmp/file.txt'))->toBeFalse(); // Missing leading /
        expect(isSafeTmpPath('./tmp/file.txt'))->toBeFalse();
    });

    it('handles double slashes by normalizing them', function () {
        // Double slashes are normalized out, so these should pass
        expect(isSafeTmpPath('/tmp//file.txt'))->toBeTrue();
        expect(isSafeTmpPath('/tmp/foo//bar.txt'))->toBeTrue();
    });

    it('handles relative directory references by normalizing them', function () {
        // ./ references are normalized out, so these should pass
        expect(isSafeTmpPath('/tmp/./file.txt'))->toBeTrue();
        expect(isSafeTmpPath('/tmp/foo/./bar.txt'))->toBeTrue();
    });

    it('handles trailing slashes correctly', function () {
        expect(isSafeTmpPath('/tmp/file.txt/'))->toBeTrue();
        expect(isSafeTmpPath('/tmp/subdir/'))->toBeTrue();
    });

    it('rejects sophisticated path traversal attempts', function () {
        // URL encoded .. will be decoded and then rejected
        expect(isSafeTmpPath('/tmp/%2e%2e/etc/passwd'))->toBeFalse();

        // Mixed case /TMP doesn't start with /tmp/
        expect(isSafeTmpPath('/TMP/file.txt'))->toBeFalse();
        expect(isSafeTmpPath('/TMP/../etc/passwd'))->toBeFalse();

        // URL encoded slashes with .. (should decode to /tmp/../../etc/passwd)
        expect(isSafeTmpPath('/tmp/..%2f..%2fetc/passwd'))->toBeFalse();

        // Null byte injection attempt (if string contains it)
        expect(isSafeTmpPath("/tmp/file.txt\0../../etc/passwd"))->toBeFalse();
    });

    it('validates paths even when directories do not exist', function () {
        // These paths don't exist but should be validated structurally
        expect(isSafeTmpPath('/tmp/nonexistent/file.txt'))->toBeTrue();
        expect(isSafeTmpPath('/tmp/totally/fake/deeply/nested/path.sql'))->toBeTrue();

        // But traversal should still be blocked even if dir doesn't exist
        expect(isSafeTmpPath('/tmp/nonexistent/../etc/passwd'))->toBeFalse();
    });

    it('handles real path resolution when directory exists', function () {
        // Create a real temp directory to test realpath() logic
        $testDir = '/tmp/phpunit-test-'.uniqid();
        mkdir($testDir, 0755, true);

        try {
            expect(isSafeTmpPath($testDir.'/file.txt'))->toBeTrue();
            expect(isSafeTmpPath($testDir.'/subdir/file.txt'))->toBeTrue();
        } finally {
            rmdir($testDir);
        }
    });

    it('prevents symlink-based traversal attacks', function () {
        // Create a temp directory and symlink
        $testDir = '/tmp/phpunit-symlink-test-'.uniqid();
        mkdir($testDir, 0755, true);

        // Try to create a symlink to /etc (may not work in all environments)
        $symlinkPath = $testDir.'/evil-link';

        try {
            // Attempt to create symlink (skip test if not possible)
            if (@symlink('/etc', $symlinkPath)) {
                // If we successfully created a symlink to /etc,
                // isSafeTmpPath should resolve it and reject paths through it
                $testPath = $symlinkPath.'/passwd';

                // The resolved path would be /etc/passwd, not /tmp/...
                // So it should be rejected
                $result = isSafeTmpPath($testPath);

                // Clean up before assertion
                unlink($symlinkPath);
                rmdir($testDir);

                expect($result)->toBeFalse();
            } else {
                // Can't create symlink, skip this specific test
                $this->markTestSkipped('Cannot create symlinks in this environment');
            }
        } catch (Exception $e) {
            // Clean up on any error
            if (file_exists($symlinkPath)) {
                unlink($symlinkPath);
            }
            if (file_exists($testDir)) {
                rmdir($testDir);
            }
            throw $e;
        }
    });

    it('has consistent behavior with or without trailing slash', function () {
        expect(isSafeTmpPath('/tmp/file.txt'))->toBe(isSafeTmpPath('/tmp/file.txt/'));
        expect(isSafeTmpPath('/tmp/subdir/file.sql'))->toBe(isSafeTmpPath('/tmp/subdir/file.sql/'));
    });
});

/**
 * Integration test for S3RestoreJobFinished event using the secure path validation.
 */
describe('S3RestoreJobFinished path validation', function () {
    it('validates that safe paths pass validation', function () {
        // Test with valid paths - should pass validation
        $validData = [
            'serverTmpPath' => '/tmp/valid-backup.sql',
            'scriptPath' => '/tmp/valid-script.sh',
            'containerTmpPath' => '/tmp/container-file.sql',
        ];

        expect(isSafeTmpPath($validData['serverTmpPath']))->toBeTrue();
        expect(isSafeTmpPath($validData['scriptPath']))->toBeTrue();
        expect(isSafeTmpPath($validData['containerTmpPath']))->toBeTrue();
    });

    it('validates that malicious paths fail validation', function () {
        // Test with malicious paths - should fail validation
        $maliciousData = [
            'serverTmpPath' => '/tmp/../etc/passwd',
            'scriptPath' => '/tmp/../../etc/shadow',
            'containerTmpPath' => '/etc/important-config',
        ];

        // Verify that our helper would reject these paths
        expect(isSafeTmpPath($maliciousData['serverTmpPath']))->toBeFalse();
        expect(isSafeTmpPath($maliciousData['scriptPath']))->toBeFalse();
        expect(isSafeTmpPath($maliciousData['containerTmpPath']))->toBeFalse();
    });

    it('validates realistic S3 restore paths', function () {
        // These are the kinds of paths that would actually be used
        $realisticPaths = [
            '/tmp/coolify-s3-restore-'.uniqid().'.sql',
            '/tmp/db-backup-'.date('Y-m-d').'.dump',
            '/tmp/restore-script-'.uniqid().'.sh',
        ];

        foreach ($realisticPaths as $path) {
            expect(isSafeTmpPath($path))->toBeTrue();
        }
    });
});
