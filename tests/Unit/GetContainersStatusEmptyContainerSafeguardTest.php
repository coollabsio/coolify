<?php

/**
 * Unit tests verifying that GetContainersStatus has empty container
 * safeguards for ALL resource types (applications, previews, databases, services).
 *
 * When Docker queries fail and return empty container lists, resources should NOT
 * be falsely marked as "exited". This was originally added for applications and
 * previews (commit 684bd823c) but was missing for databases and services.
 *
 * @see https://github.com/coollabsio/coolify/issues/8826
 */
it('has empty container safeguard for applications', function () {
    $actionFile = file_get_contents(__DIR__.'/../../app/Actions/Docker/GetContainersStatus.php');

    // The safeguard should appear before marking applications as exited
    expect($actionFile)
        ->toContain('$notRunningApplications = $this->applications->pluck(\'id\')->diff($foundApplications);');

    // Count occurrences of the safeguard pattern in the not-found sections
    $safeguardPattern = '// Only protection: If no containers at all, Docker query might have failed';
    $safeguardCount = substr_count($actionFile, $safeguardPattern);

    // Should appear at least 4 times: applications, previews, databases, services
    expect($safeguardCount)->toBeGreaterThanOrEqual(4);
});

it('has empty container safeguard for databases', function () {
    $actionFile = file_get_contents(__DIR__.'/../../app/Actions/Docker/GetContainersStatus.php');

    // Extract the database not-found section
    $databaseSectionStart = strpos($actionFile, '$notRunningDatabases = $databases->pluck(\'id\')->diff($foundDatabases);');
    expect($databaseSectionStart)->not->toBeFalse('Database not-found section should exist');

    // Get the code between database section start and the next major section
    $databaseSection = substr($actionFile, $databaseSectionStart, 500);

    // The empty container safeguard must exist in the database section
    expect($databaseSection)->toContain('$this->containers->isEmpty()');
});

it('has empty container safeguard for services', function () {
    $actionFile = file_get_contents(__DIR__.'/../../app/Actions/Docker/GetContainersStatus.php');

    // Extract the service exited section
    $serviceSectionStart = strpos($actionFile, '$exitedServices = $exitedServices->unique(\'uuid\');');
    expect($serviceSectionStart)->not->toBeFalse('Service exited section should exist');

    // Get the code in the service exited loop
    $serviceSection = substr($actionFile, $serviceSectionStart, 500);

    // The empty container safeguard must exist in the service section
    expect($serviceSection)->toContain('$this->containers->isEmpty()');
});
