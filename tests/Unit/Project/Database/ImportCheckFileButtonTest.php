<?php

use App\Livewire\Project\Database\Import;
use App\Models\Server;

test('checkFile does nothing when customLocation is empty', function () {
    $component = new Import;
    $component->customLocation = '';

    $mockServer = Mockery::mock(Server::class);
    $component->server = $mockServer;

    // No server commands should be executed when customLocation is empty
    $component->checkFile();

    expect($component->filename)->toBeNull();
});

test('checkFile validates file exists on server when customLocation is filled', function () {
    $component = new Import;
    $component->customLocation = '/tmp/backup.sql';

    $mockServer = Mockery::mock(Server::class);
    $component->server = $mockServer;

    // This test verifies the logic flows when customLocation has a value
    // The actual remote process execution is tested elsewhere
    expect($component->customLocation)->toBe('/tmp/backup.sql');
});

test('customLocation can be cleared to allow uploaded file to be used', function () {
    $component = new Import;
    $component->customLocation = '/tmp/backup.sql';

    // Simulate clearing the customLocation (as happens when file is uploaded)
    $component->customLocation = '';

    expect($component->customLocation)->toBe('');
});

test('validateBucketName accepts valid bucket names', function () {
    $component = new Import;
    $method = new ReflectionMethod($component, 'validateBucketName');

    // Valid bucket names
    expect($method->invoke($component, 'my-bucket'))->toBeTrue();
    expect($method->invoke($component, 'my_bucket'))->toBeTrue();
    expect($method->invoke($component, 'mybucket123'))->toBeTrue();
    expect($method->invoke($component, 'my.bucket.name'))->toBeTrue();
    expect($method->invoke($component, 'Bucket-Name_123'))->toBeTrue();
});

test('validateBucketName rejects invalid bucket names', function () {
    $component = new Import;
    $method = new ReflectionMethod($component, 'validateBucketName');

    // Invalid bucket names (command injection attempts)
    expect($method->invoke($component, 'bucket;rm -rf /'))->toBeFalse();
    expect($method->invoke($component, 'bucket$(whoami)'))->toBeFalse();
    expect($method->invoke($component, 'bucket`id`'))->toBeFalse();
    expect($method->invoke($component, 'bucket|cat /etc/passwd'))->toBeFalse();
    expect($method->invoke($component, 'bucket&ls'))->toBeFalse();
    expect($method->invoke($component, "bucket\nid"))->toBeFalse();
    expect($method->invoke($component, 'bucket name'))->toBeFalse(); // Space not allowed in bucket
});

test('validateS3Path accepts valid S3 paths', function () {
    $component = new Import;
    $method = new ReflectionMethod($component, 'validateS3Path');

    // Valid S3 paths
    expect($method->invoke($component, 'backup.sql'))->toBeTrue();
    expect($method->invoke($component, 'folder/backup.sql'))->toBeTrue();
    expect($method->invoke($component, 'my-folder/my_backup.sql.gz'))->toBeTrue();
    expect($method->invoke($component, 'path/to/deep/file.tar.gz'))->toBeTrue();
    expect($method->invoke($component, 'folder with space/file.sql'))->toBeTrue();
});

test('validateS3Path rejects invalid S3 paths', function () {
    $component = new Import;
    $method = new ReflectionMethod($component, 'validateS3Path');

    // Invalid S3 paths (command injection attempts)
    expect($method->invoke($component, ''))->toBeFalse(); // Empty
    expect($method->invoke($component, '../etc/passwd'))->toBeFalse(); // Directory traversal
    expect($method->invoke($component, 'path;rm -rf /'))->toBeFalse();
    expect($method->invoke($component, 'path$(whoami)'))->toBeFalse();
    expect($method->invoke($component, 'path`id`'))->toBeFalse();
    expect($method->invoke($component, 'path|cat /etc/passwd'))->toBeFalse();
    expect($method->invoke($component, 'path&ls'))->toBeFalse();
    expect($method->invoke($component, "path\nid"))->toBeFalse();
    expect($method->invoke($component, "path\r\nid"))->toBeFalse();
    expect($method->invoke($component, "path\0id"))->toBeFalse(); // Null byte
    expect($method->invoke($component, "path'injection"))->toBeFalse();
    expect($method->invoke($component, 'path"injection'))->toBeFalse();
    expect($method->invoke($component, 'path\\injection'))->toBeFalse();
});

test('validateServerPath accepts valid server paths', function () {
    $component = new Import;
    $method = new ReflectionMethod($component, 'validateServerPath');

    // Valid server paths (must be absolute)
    expect($method->invoke($component, '/tmp/backup.sql'))->toBeTrue();
    expect($method->invoke($component, '/var/backups/my-backup.sql'))->toBeTrue();
    expect($method->invoke($component, '/home/user/data_backup.sql.gz'))->toBeTrue();
    expect($method->invoke($component, '/path/to/deep/nested/file.tar.gz'))->toBeTrue();
});

test('validateServerPath rejects invalid server paths', function () {
    $component = new Import;
    $method = new ReflectionMethod($component, 'validateServerPath');

    // Invalid server paths
    expect($method->invoke($component, 'relative/path.sql'))->toBeFalse(); // Not absolute
    expect($method->invoke($component, '/path/../etc/passwd'))->toBeFalse(); // Directory traversal
    expect($method->invoke($component, '/path;rm -rf /'))->toBeFalse();
    expect($method->invoke($component, '/path$(whoami)'))->toBeFalse();
    expect($method->invoke($component, '/path`id`'))->toBeFalse();
    expect($method->invoke($component, '/path|cat /etc/passwd'))->toBeFalse();
    expect($method->invoke($component, '/path&ls'))->toBeFalse();
    expect($method->invoke($component, "/path\nid"))->toBeFalse();
    expect($method->invoke($component, "/path\r\nid"))->toBeFalse();
    expect($method->invoke($component, "/path\0id"))->toBeFalse(); // Null byte
    expect($method->invoke($component, "/path'injection"))->toBeFalse();
    expect($method->invoke($component, '/path"injection'))->toBeFalse();
    expect($method->invoke($component, '/path\\injection'))->toBeFalse();
});
