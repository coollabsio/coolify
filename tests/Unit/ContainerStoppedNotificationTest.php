<?php

/**
 * Unit tests verifying that ContainerStopped notifications are sent when
 * containers exit unexpectedly.
 *
 * Previously disabled due to spam concerns (commit 086138fbd), notifications
 * are now re-enabled with a 60-minute throttle per resource to prevent
 * notification fatigue while still alerting users to real crashes.
 *
 * @see https://github.com/coollabsio/coolify/issues/9493
 */
it('has ContainerStopped notification for exited services', function () {
    $actionFile = file_get_contents(__DIR__.'/../../app/Actions/Docker/GetContainersStatus.php');

    // Find the exited services section
    $serviceSectionStart = strpos($actionFile, '$exitedServices = $exitedServices->unique(\'uuid\');');
    expect($serviceSectionStart)->not->toBeFalse('Service exited section should exist');

    // Get the code for exited services
    $serviceSection = substr($actionFile, $serviceSectionStart, 800);

    // Should contain the notification call (uncommented)
    expect($serviceSection)
        ->toContain('ContainerStopped')
        ->toContain('last_stop_notification_at');

    // Should NOT have the commented-out notification pattern
    expect($serviceSection)
        ->not->toContain('// $this->server->team?->notify(new ContainerStopped');
});

it('has ContainerStopped notification for exited databases', function () {
    $actionFile = file_get_contents(__DIR__.'/../../app/Actions/Docker/GetContainersStatus.php');

    // Find the notRunningDatabases section
    $databaseSectionStart = strpos($actionFile, '$notRunningDatabases = $databases->pluck(\'id\')->diff($foundDatabases);');
    expect($databaseSectionStart)->not->toBeFalse('Database not-found section should exist');

    // Get the code for the database section
    $databaseSection = substr($actionFile, $databaseSectionStart, 1000);

    // Should contain the notification call (uncommented) with throttle
    expect($databaseSection)
        ->toContain('ContainerStopped')
        ->toContain('last_stop_notification_at');

    // Should NOT have the commented-out notification pattern
    expect($databaseSection)
        ->not->toContain('// $this->server->team?->notify(new ContainerStopped');
});

it('has stop notification throttle of 60 minutes', function () {
    $actionFile = file_get_contents(__DIR__.'/../../app/Actions/Docker/GetContainersStatus.php');

    // Count occurrences of the throttle pattern
    $throttlePattern = 'lessThan(now()->subMinutes(60))';
    $throttleCount = substr_count($actionFile, $throttlePattern);

    // Should appear twice: once for services, once for databases
    expect($throttleCount)->toBe(2);
});

it('updates last_stop_notification_at after sending notification', function () {
    $actionFile = file_get_contents(__DIR__.'/../../app/Actions/Docker/GetContainersStatus.php');

    // Should update last_stop_notification_at after sending notification
    $updatePattern = "'last_stop_notification_at\' => now()";
    $updateCount = substr_count($actionFile, $updatePattern);

    // Should appear twice: services and databases
    expect($updateCount)->toBe(2);
});

it('migration adds last_stop_notification_at column', function () {
    $migrationFile = file_get_contents(__DIR__.'/../../database/migrations/2026_06_01_000000_add_stop_notification_tracking.php');

    expect($migrationFile)
        ->toContain('applications')
        ->toContain('standalone_databases')
        ->toContain('last_stop_notification_at');
});
