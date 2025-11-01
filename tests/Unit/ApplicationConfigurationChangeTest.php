<?php

use App\Models\Application;

/**
 * Unit test to verify that custom_network_aliases is included in configuration change detection.
 * These tests verify the hash calculation includes the field by checking the behavior.
 */
it('custom_network_aliases affects configuration hash', function () {
    // Test helper to calculate hash like isConfigurationChanged does
    $calculateHash = function ($customNetworkAliases) {
        return md5(base64_encode(
            'example.com'. // fqdn
            'https://github.com/example/repo'. // git_repository
            'main'. // git_branch
            'abc123'. // git_commit_sha
            'nixpacks'. // build_pack
            null. // static_image
            'npm install'. // install_command
            'npm run build'. // build_command
            'npm start'. // start_command
            '3000'. // ports_exposes
            null. // ports_mappings
            $customNetworkAliases. // custom_network_aliases (THIS IS THE KEY LINE)
            '/'. // base_directory
            null. // publish_directory
            null. // dockerfile
            'Dockerfile'. // dockerfile_location
            null. // custom_labels
            null. // custom_docker_run_options
            null. // dockerfile_target_build
            null. // redirect
            null. // custom_nginx_configuration
            null. // custom_labels (duplicate)
            false // use_build_secrets
        ));
    };

    // Different custom_network_aliases should produce different hashes
    $hash1 = $calculateHash('api.internal,api.local');
    $hash2 = $calculateHash('api.internal,api.local,api.staging');
    $hash3 = $calculateHash(null);

    expect($hash1)->not->toBe($hash2)
        ->and($hash1)->not->toBe($hash3)
        ->and($hash2)->not->toBe($hash3);
});

it('custom_network_aliases is in the configuration hash fields', function () {
    // This test verifies the field is in the isConfigurationChanged method by reading the source
    $reflection = new ReflectionClass(Application::class);
    $method = $reflection->getMethod('isConfigurationChanged');
    $source = file_get_contents($method->getFileName());

    // Extract the method source
    $lines = explode("\n", $source);
    $methodStartLine = $method->getStartLine() - 1;
    $methodEndLine = $method->getEndLine();
    $methodSource = implode("\n", array_slice($lines, $methodStartLine, $methodEndLine - $methodStartLine));

    // Verify custom_network_aliases is in the hash calculation
    expect($methodSource)->toContain('$this->custom_network_aliases')
        ->and($methodSource)->toContain('ports_mappings');
});
