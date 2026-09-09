<?php

use App\Rules\ValidS3BucketName;

function validS3BucketNameRulePasses(string $bucket): bool
{
    $failed = false;

    (new ValidS3BucketName)->validate('bucket', $bucket, function () use (&$failed) {
        $failed = true;
    });

    return ! $failed;
}

it('accepts valid s3 bucket names', function (string $bucket) {
    expect(validS3BucketNameRulePasses($bucket))->toBeTrue("Expected accepted: {$bucket}");
})->with([
    'short' => ['abc'],
    'simple' => ['coolify-backups'],
    'dots' => ['coolify.backups'],
    'digits' => ['backup-123'],
    'max length' => [str_repeat('a', 63)],
]);

it('rejects invalid s3 bucket names and injection payloads', function (string $bucket) {
    expect(validS3BucketNameRulePasses($bucket))->toBeFalse("Expected rejected: {$bucket}");
})->with([
    'too short' => ['ab'],
    'too long' => [str_repeat('a', 64)],
    'uppercase' => ['CoolifyBackups'],
    'underscore' => ['coolify_backups'],
    'leading hyphen' => ['-coolify-backups'],
    'trailing hyphen' => ['coolify-backups-'],
    'leading dot' => ['.coolify-backups'],
    'trailing dot' => ['coolify-backups.'],
    'consecutive dots' => ['coolify..backups'],
    'dot hyphen' => ['coolify.-backups'],
    'hyphen dot' => ['coolify-.backups'],
    'ipv4 address' => ['192.168.1.1'],
    'semicolon injection' => ['lab; id; #'],
    'command substitution' => ['lab$(id)'],
    'backticks' => ['lab`id`'],
    'pipe' => ['lab|id'],
    'ampersand' => ['lab&id'],
    'space' => ['lab bucket'],
    'newline' => ["lab\nid"],
]);
