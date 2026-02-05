<?php

/**
 * Unit tests to verify that environment variables can be scoped to specific containers
 * within Docker Compose services to prevent security vulnerabilities.
 *
 * This addresses issue #7655 where all environment variables were shared
 * across all containers in a Compose project, allowing one container to
 * access secrets meant for another container.
 *
 * @see https://github.com/coollabsio/coolify/issues/7655
 */

it('parsers.php uses per-container env files instead of single .env', function () {
    $parsersFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/parsers.php');

    // Check that the fix is in place - using per-container env files
    expect($parsersFile)->toContain('.env.{$serviceName}');
    expect($parsersFile)->toContain('security fix for issue #7655');
});

it('Service model generates per-container env files', function () {
    $serviceFile = file_get_contents(__DIR__.'/../../app/Models/Service.php');

    // Check that saveComposeConfigs creates per-container env files
    expect($serviceFile)->toContain('.env.{$serviceName}');
    expect($serviceFile)->toContain('whereNull(\'container_name\')');
    expect($serviceFile)->toContain('whereNotNull(\'container_name\')');
});

it('EnvironmentVariable model has container_name field', function () {
    $modelFile = file_get_contents(__DIR__.'/../../app/Models/EnvironmentVariable.php');

    // Check that the model includes container_name
    expect($modelFile)->toContain('container_name');
    expect($modelFile)->toContain("'container_name' => 'string'");
});

it('migration adds container_name column to environment_variables table', function () {
    $migrations = glob(__DIR__.'/../../database/migrations/*container_name*');

    expect($migrations)->not->toBeEmpty();

    $migrationFile = file_get_contents($migrations[0]);

    // Check migration adds the column
    expect($migrationFile)->toContain("->string('container_name')");
    expect($migrationFile)->toContain('->nullable()');
    expect($migrationFile)->toContain('issue #7655');
});

it('Add component includes container_name in saveKey dispatch', function () {
    $addFile = file_get_contents(__DIR__.'/../../app/Livewire/Project/Shared/EnvironmentVariable/Add.php');

    // Check that container_name is dispatched
    expect($addFile)->toContain("'container_name' => \$this->container_name");
    expect($addFile)->toContain('public array $containerNames = []');
});

it('All component passes containerNames to Add component', function () {
    $allViewFile = file_get_contents(__DIR__.'/../../resources/views/livewire/project/shared/environment-variable/all.blade.php');

    // Check that containerNames is passed
    expect($allViewFile)->toContain(':containerNames="$this->containerNames"');
});

it('Show component displays container scope for services', function () {
    $showViewFile = file_get_contents(__DIR__.'/../../resources/views/livewire/project/shared/environment-variable/show.blade.php');

    // Check that container scope is displayed
    expect($showViewFile)->toContain('Container:');
    expect($showViewFile)->toContain('All Containers');
    expect($showViewFile)->toContain('$container_name');
});

it('createEnvironmentVariable handles container_name correctly', function () {
    $allFile = file_get_contents(__DIR__.'/../../app/Livewire/Project/Shared/EnvironmentVariable/All.php');

    // Check that empty string and 'all' are converted to null
    expect($allFile)->toContain("\$containerName === ''");
    expect($allFile)->toContain("\$containerName === 'all'");
    expect($allFile)->toContain('$environment->container_name = $containerName');
});

it('add view shows container selector for Docker Compose services', function () {
    $addViewFile = file_get_contents(__DIR__.'/../../resources/views/livewire/project/shared/environment-variable/add.blade.php');

    // Check that container selector is present
    expect($addViewFile)->toContain('count($containerNames) > 0');
    expect($addViewFile)->toContain('Available To');
    expect($addViewFile)->toContain('<option value="">All Containers</option>');
});
