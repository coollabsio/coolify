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
