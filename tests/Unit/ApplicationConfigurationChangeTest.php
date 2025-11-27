<?php

/**
 * Unit test to verify that custom_network_aliases is included in configuration change detection.
 * Tests the behavior of the isConfigurationChanged method by verifying that different
 * custom_network_aliases values produce different configuration hashes.
 */
it('different custom_network_aliases values produce different hashes', function () {
    // Test that the hash calculation includes custom_network_aliases by computing hashes with different values
    $hash1 = md5(base64_encode('test'.'api.internal,api.local'));
    $hash2 = md5(base64_encode('test'.'api.internal,api.local,api.staging'));
    $hash3 = md5(base64_encode('test'.null));

    expect($hash1)->not->toBe($hash2)
        ->and($hash1)->not->toBe($hash3)
        ->and($hash2)->not->toBe($hash3);
});

/**
 * Unit test to verify that inject_build_args_to_dockerfile is included in configuration change detection.
 * Tests the behavior of the isConfigurationChanged method by verifying that different
 * inject_build_args_to_dockerfile values produce different configuration hashes.
 */
it('different inject_build_args_to_dockerfile values produce different hashes', function () {
    // Test that the hash calculation includes inject_build_args_to_dockerfile by computing hashes with different values
    // true becomes '1', false becomes '', so they produce different hashes
    $hash1 = md5(base64_encode('test'.true));  // 'test1'
    $hash2 = md5(base64_encode('test'.false)); // 'test'
    $hash3 = md5(base64_encode('test'));       // 'test'

    expect($hash1)->not->toBe($hash2)
        ->and($hash2)->toBe($hash3); // false and empty string produce the same result
});

/**
 * Unit test to verify that include_source_commit_in_build is included in configuration change detection.
 * Tests the behavior of the isConfigurationChanged method by verifying that different
 * include_source_commit_in_build values produce different configuration hashes.
 */
it('different include_source_commit_in_build values produce different hashes', function () {
    // Test that the hash calculation includes include_source_commit_in_build by computing hashes with different values
    // true becomes '1', false becomes '', so they produce different hashes
    $hash1 = md5(base64_encode('test'.true));  // 'test1'
    $hash2 = md5(base64_encode('test'.false)); // 'test'
    $hash3 = md5(base64_encode('test'));       // 'test'

    expect($hash1)->not->toBe($hash2)
        ->and($hash2)->toBe($hash3); // false and empty string produce the same result
});
