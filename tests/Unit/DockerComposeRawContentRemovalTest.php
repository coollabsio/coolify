<?php

/**
 * Unit tests to verify that docker_compose_raw only has content: removed from volumes,
 * while docker_compose contains all Coolify additions (labels, environment variables, networks).
 *
 * These tests verify the fix for the issue where docker_compose_raw was being set to the
 * fully processed compose (with Coolify labels, networks, etc.) instead of keeping it clean
 * with only content: fields removed.
 */
it('ensures applicationParser stores original compose before processing', function () {
    // Read the applicationParser function from parsers.php
    $parsersFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/parsers.php');

    // Check that originalCompose is stored at the start of the function
    expect($parsersFile)
        ->toContain('$compose = data_get($resource, \'docker_compose_raw\');')
        ->toContain('// Store original compose for later use to update docker_compose_raw with content removed')
        ->toContain('$originalCompose = $compose;');
});

it('ensures serviceParser stores original compose before processing', function () {
    // Read the serviceParser function from parsers.php
    $parsersFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/parsers.php');

    // Check that originalCompose is stored at the start of the function
    expect($parsersFile)
        ->toContain('function serviceParser(Service $resource): Collection')
        ->toContain('$compose = data_get($resource, \'docker_compose_raw\');')
        ->toContain('// Store original compose for later use to update docker_compose_raw with content removed')
        ->toContain('$originalCompose = $compose;');
});

it('ensures applicationParser updates docker_compose_raw from original compose, not cleaned compose', function () {
    // Read the applicationParser function from parsers.php
    $parsersFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/parsers.php');

    // Check that docker_compose_raw is set from originalCompose, not cleanedCompose
    expect($parsersFile)
        ->toContain('$originalYaml = Yaml::parse($originalCompose);')
        ->toContain('$resource->docker_compose_raw = Yaml::dump($originalYaml, 10, 2);')
        ->not->toContain('$resource->docker_compose_raw = $cleanedCompose;');
});

it('ensures serviceParser updates docker_compose_raw from original compose, not cleaned compose', function () {
    // Read the serviceParser function from parsers.php
    $parsersFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/parsers.php');

    // Find the serviceParser function content
    $serviceParserStart = strpos($parsersFile, 'function serviceParser(Service $resource): Collection');
    $serviceParserContent = substr($parsersFile, $serviceParserStart);

    // Check that docker_compose_raw is set from originalCompose within serviceParser
    expect($serviceParserContent)
        ->toContain('$originalYaml = Yaml::parse($originalCompose);')
        ->toContain('$resource->docker_compose_raw = Yaml::dump($originalYaml, 10, 2);')
        ->not->toContain('$resource->docker_compose_raw = $cleanedCompose;');
});

it('ensures applicationParser removes content, isDirectory, and is_directory from volumes', function () {
    // Read the applicationParser function from parsers.php
    $parsersFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/parsers.php');

    // Check that content removal logic exists
    expect($parsersFile)
        ->toContain('// Remove content, isDirectory, and is_directory from all volume definitions')
        ->toContain("unset(\$volume['content']);")
        ->toContain("unset(\$volume['isDirectory']);")
        ->toContain("unset(\$volume['is_directory']);");
});

it('ensures serviceParser removes content, isDirectory, and is_directory from volumes', function () {
    // Read the serviceParser function from parsers.php
    $parsersFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/parsers.php');

    // Find the serviceParser function content
    $serviceParserStart = strpos($parsersFile, 'function serviceParser(Service $resource): Collection');
    $serviceParserContent = substr($parsersFile, $serviceParserStart);

    // Check that content removal logic exists within serviceParser
    expect($serviceParserContent)
        ->toContain('// Remove content, isDirectory, and is_directory from all volume definitions')
        ->toContain("unset(\$volume['content']);")
        ->toContain("unset(\$volume['isDirectory']);")
        ->toContain("unset(\$volume['is_directory']);");
});

it('ensures docker_compose_raw update is wrapped in try-catch for error handling', function () {
    // Read the parsers file
    $parsersFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/parsers.php');

    // Check that docker_compose_raw update has error handling
    expect($parsersFile)
        ->toContain('// Update docker_compose_raw to remove content: from volumes only')
        ->toContain('// This keeps the original user input clean while preventing content reapplication')
        ->toContain('try {')
        ->toContain('$originalYaml = Yaml::parse($originalCompose);')
        ->toContain('} catch (\Exception $e) {')
        ->toContain("ray('Failed to update docker_compose_raw");
});
