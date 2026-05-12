<?php

/**
 * Tests to verify that environment variables are NOT automatically shared across all
 * containers in a Docker Compose project.
 *
 * Before the fix, Coolify injected `env_file: ['.env']` into every service, causing
 * every container to receive all environment variables from the entire project. This
 * is a security issue because secrets intended for one service leak to all others.
 *
 * The correct Docker Compose behaviour:
 *  - `.env` is used only for variable interpolation via --env-file flag.
 *  - Runtime environment variables must be explicitly declared per service.
 *
 * See: https://github.com/coollabsio/coolify/issues/7655
 */

it('ensures applicationParser does not auto-inject global .env into every service', function () {
    $parsersFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/parsers.php');

    // Extract only the applicationParser function body
    $start = strpos($parsersFile, 'function applicationParser(');
    $serviceParserStart = strpos($parsersFile, 'function serviceParser(');
    $applicationParserContent = substr($parsersFile, $start, $serviceParserStart - $start);

    // The parser must NOT push '.env' into every service's env_file unconditionally
    expect($applicationParserContent)->not->toContain("->push('.env')");

    // The parser must NOT always assign env_file for every service
    expect($applicationParserContent)->not->toContain("'env_file'] = \$envFiles");
});

it('ensures applicationParser preserves explicitly declared env_file entries', function () {
    $parsersFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/parsers.php');

    // Extract only the applicationParser function body
    $start = strpos($parsersFile, 'function applicationParser(');
    $serviceParserStart = strpos($parsersFile, 'function serviceParser(');
    $applicationParserContent = substr($parsersFile, $start, $serviceParserStart - $start);

    // The parser must still read existing env_file entries from the user's compose file
    expect($applicationParserContent)->toContain("data_get(\$service, 'env_file')");

    // And must only set env_file when the user has explicitly declared it
    expect($applicationParserContent)->toContain('if (! is_null($existingEnvFiles))');
});

it('ensures ApplicationDeploymentJob does not forcibly override env_file for all services', function () {
    $jobFile = file_get_contents(__DIR__.'/../../app/Jobs/ApplicationDeploymentJob.php');

    // Extract the deploy_docker_compose_buildpack method
    $start = strpos($jobFile, 'private function deploy_docker_compose_buildpack()');
    $nextMethod = strpos($jobFile, 'private function ', $start + 1);
    $methodContent = substr($jobFile, $start, $nextMethod - $start);

    // The deployment method must NOT iterate over services to set env_file = ['.env']
    expect($methodContent)->not->toContain("\$service['env_file'] = ['.env']");

    // The deployment method must NOT forcibly rewrite env_file for parsed services
    expect($methodContent)->not->toContain("'env_file'] = ['.env']");
});

it('ensures .env file is still created for docker compose interpolation', function () {
    $jobFile = file_get_contents(__DIR__.'/../../app/Jobs/ApplicationDeploymentJob.php');

    // Extract save_runtime_environment_variables method
    $start = strpos($jobFile, 'private function save_runtime_environment_variables()');
    $nextMethod = strpos($jobFile, 'private function ', $start + 1);
    $methodContent = substr($jobFile, $start, $nextMethod - $start);

    // The .env file must still be created for dockercompose (needed for --env-file interpolation)
    expect($methodContent)->toContain("'dockercompose'");
    expect($methodContent)->toContain("touch");
});
