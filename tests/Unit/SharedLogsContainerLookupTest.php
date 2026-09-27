<?php

/**
 * Runtime Logs must query Docker with the label that matches the resource type.
 * Applications use coolify.applicationId, services coolify.serviceId, databases coolify.databaseId.
 * Using the application helper for services/databases returns empty results even when containers run.
 */
it('looks up containers with the correct helper for each resource type', function () {
    $logsFile = file_get_contents(__DIR__.'/../../app/Livewire/Project/Shared/Logs.php');

    expect($logsFile)
        ->toContain('getCurrentApplicationContainerStatus(')
        ->toContain('getCurrentServiceContainerStatus(')
        ->toContain('getCurrentDatabaseContainerStatus(')
        ->toContain('$this->resource instanceof Application')
        ->toContain('$this->resource instanceof Service');
});

it('filters application containers by coolify.applicationId', function () {
    $dockerHelpers = file_get_contents(__DIR__.'/../../bootstrap/helpers/docker.php');

    expect($dockerHelpers)->toContain('label=coolify.applicationId=');
});

it('filters service and database containers by the correct coolify labels', function () {
    $dockerHelpers = file_get_contents(__DIR__.'/../../bootstrap/helpers/docker.php');

    expect($dockerHelpers)
        ->toContain('function getCurrentServiceContainerStatus')
        ->toContain('label=coolify.serviceId=')
        ->toContain('function getCurrentDatabaseContainerStatus')
        ->toContain('label=coolify.databaseId=')
        ->toContain('label=coolify.database.subType=');
});

it('filters database containers by subType in api and logs to prevent cross-database collision', function () {
    $databasesController = file_get_contents(__DIR__.'/../../app/Http/Controllers/Api/DatabasesController.php');
    $logsFile = file_get_contents(__DIR__.'/../../app/Livewire/Project/Shared/Logs.php');

    expect($databasesController)
        ->toContain('getCurrentDatabaseContainerStatus($database->destination->server, $database->id, $database->type())')
        ->toContain('$database->uuid');

    expect($logsFile)
        ->toContain('getCurrentDatabaseContainerStatus(')
        ->toContain('$this->resource->type()');
});
