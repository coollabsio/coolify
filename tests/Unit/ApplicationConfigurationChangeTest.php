<?php

use App\Models\Application;

/**
 * Unit test to verify that custom_network_aliases is included in configuration change detection.
 * Tests exercise the real Application::isConfigurationChanged() method.
 */
it('detects custom_network_aliases change as configuration change', function () {
    // Create a partial mock of Application with environment_variables mocked
    $app = \Mockery::mock(Application::class)->makePartial();
    // Mock environment_variables to return an empty collection that supports get()
    $emptyCollection = collect([])->makeHidden([]);
    $app->shouldReceive('environment_variables')->andReturn(\Mockery::mock(function ($mock) {
        $mock->shouldReceive('get')->andReturn(collect([]));
    }));

    // Set attributes for initial configuration
    $app->fqdn = 'example.com';
    $app->git_repository = 'https://github.com/example/repo';
    $app->git_branch = 'main';
    $app->git_commit_sha = 'abc123';
    $app->build_pack = 'nixpacks';
    $app->static_image = null;
    $app->install_command = 'npm install';
    $app->build_command = 'npm run build';
    $app->start_command = 'npm start';
    $app->ports_exposes = '3000';
    $app->ports_mappings = null;
    $app->custom_network_aliases = 'api.internal,api.local';
    $app->base_directory = '/';
    $app->publish_directory = null;
    $app->dockerfile = null;
    $app->dockerfile_location = 'Dockerfile';
    $app->custom_labels = null;
    $app->custom_docker_run_options = null;
    $app->dockerfile_target_build = null;
    $app->redirect = null;
    $app->custom_nginx_configuration = null;
    $app->pull_request_id = 0;

    // Mock the settings relationship
    $settings = \Mockery::mock();
    $settings->use_build_secrets = false;
    $app->setRelation('settings', $settings);

    // Get the initial configuration hash
    $app->isConfigurationChanged(true);
    $initialHash = $app->config_hash;
    expect($initialHash)->not->toBeNull();

    // Change custom_network_aliases
    $app->custom_network_aliases = 'api.internal,api.local,api.staging';

    // Verify configuration is detected as changed
    $isChanged = $app->isConfigurationChanged(false);
    expect($isChanged)->toBeTrue();
});

it('does not detect change when custom_network_aliases stays the same', function () {
    // Create a partial mock of Application with environment_variables mocked
    $app = \Mockery::mock(Application::class)->makePartial();
    // Mock environment_variables to return an empty collection that supports get()
    $app->shouldReceive('environment_variables')->andReturn(\Mockery::mock(function ($mock) {
        $mock->shouldReceive('get')->andReturn(collect([]));
    }));

    // Set attributes for initial configuration
    $app->fqdn = 'example.com';
    $app->git_repository = 'https://github.com/example/repo';
    $app->git_branch = 'main';
    $app->git_commit_sha = 'abc123';
    $app->build_pack = 'nixpacks';
    $app->static_image = null;
    $app->install_command = 'npm install';
    $app->build_command = 'npm run build';
    $app->start_command = 'npm start';
    $app->ports_exposes = '3000';
    $app->ports_mappings = null;
    $app->custom_network_aliases = 'api.internal,api.local';
    $app->base_directory = '/';
    $app->publish_directory = null;
    $app->dockerfile = null;
    $app->dockerfile_location = 'Dockerfile';
    $app->custom_labels = null;
    $app->custom_docker_run_options = null;
    $app->dockerfile_target_build = null;
    $app->redirect = null;
    $app->custom_nginx_configuration = null;
    $app->pull_request_id = 0;

    // Mock the settings relationship
    $settings = \Mockery::mock();
    $settings->use_build_secrets = false;
    $app->setRelation('settings', $settings);

    // Get the initial configuration hash
    $app->isConfigurationChanged(true);
    $initialHash = $app->config_hash;

    // Keep custom_network_aliases the same
    $app->custom_network_aliases = 'api.internal,api.local';

    // Verify configuration is NOT detected as changed
    $isChanged = $app->isConfigurationChanged(false);
    expect($isChanged)->toBeFalse();
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
