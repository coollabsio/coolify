<?php

/**
 * Unit tests to verify that docker compose label parsing correctly handles
 * labels defined as YAML key-value pairs (e.g., "traefik.enable: true")
 * which get parsed as arrays instead of strings.
 *
 * This test verifies the fix for the "preg_match(): Argument #2 ($subject) must
 * be of type string, array given" error.
 */
it('ensures label parsing handles array values from YAML', function () {
    // Read the parseDockerComposeFile function from shared.php
    $sharedFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/shared.php');

    // Check that array handling is present before str() call
    expect($sharedFile)
        ->toContain('// Handle array values from YAML (e.g., "traefik.enable: true" becomes an array)')
        ->toContain('if (is_array($serviceLabel)) {');
});

it('ensures label parsing converts array values to strings', function () {
    // Read the parseDockerComposeFile function from shared.php
    $sharedFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/shared.php');

    // Check that array to string conversion exists
    expect($sharedFile)
        ->toContain('// Convert array values to strings')
        ->toContain('if (is_array($removedLabel)) {')
        ->toContain('$removedLabel = (string) collect($removedLabel)->first();');
});

it('verifies label parsing array check occurs before preg_match', function () {
    // Read the parseDockerComposeFile function from shared.php
    $sharedFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/shared.php');

    // Get the position of array check and str() call
    $arrayCheckPos = strpos($sharedFile, 'if (is_array($serviceLabel)) {');
    $strCallPos = strpos($sharedFile, "str(\$serviceLabel)->contains('=')");

    // Ensure array check comes before str() call
    expect($arrayCheckPos)
        ->toBeLessThan($strCallPos)
        ->toBeGreaterThan(0);
});

it('ensures traefik middleware parsing handles array values in docker.php', function () {
    // Read the fqdnLabelsForTraefik function from docker.php
    $dockerFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/docker.php');

    // Check that array handling is present before preg_match
    expect($dockerFile)
        ->toContain('// Handle array values from YAML parsing (e.g., "traefik.enable: true" becomes an array)')
        ->toContain('if (is_array($item)) {');
});

it('ensures traefik middleware parsing checks string type before preg_match in docker.php', function () {
    // Read the fqdnLabelsForTraefik function from docker.php
    $dockerFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/docker.php');

    // Check that string type check exists
    expect($dockerFile)
        ->toContain('if (! is_string($item)) {')
        ->toContain('return null;');
});

it('verifies array check occurs before preg_match in traefik middleware parsing', function () {
    // Read the fqdnLabelsForTraefik function from docker.php
    $dockerFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/docker.php');

    // Get the position of array check and preg_match call
    $arrayCheckPos = strpos($dockerFile, 'if (is_array($item)) {');
    $pregMatchPos = strpos($dockerFile, "preg_match('/traefik\\.http\\.middlewares\\.(.*?)(\\.|$)/', \$item");

    // Ensure array check comes before preg_match call (find first occurrence after array check)
    $pregMatchAfterArrayCheck = strpos($dockerFile, "preg_match('/traefik\\.http\\.middlewares\\.(.*?)(\\.|$)/', \$item", $arrayCheckPos);
    expect($arrayCheckPos)
        ->toBeLessThan($pregMatchAfterArrayCheck)
        ->toBeGreaterThan(0);
});
