<?php

use App\Models\Application;

it('stores nullable application container presence as a boolean', function () {
    $application = new Application;
    $application->forceFill(['container_present' => 1]);
    $migration = file_get_contents(base_path('database/migrations/2026_08_30_193506_add_container_present_to_applications_table.php'));

    expect($application->container_present)->toBeTrue()
        ->and($migration)->toContain("boolean('container_present')->nullable()");
});

it('updates container presence at application lifecycle boundaries', function () {
    $stopAction = file_get_contents(app_path('Actions/Application/StopApplication.php'));
    $dockerStatus = file_get_contents(app_path('Actions/Docker/GetContainersStatus.php'));
    $sentinelStatus = file_get_contents(app_path('Jobs/PushServerUpdateJob.php'));

    expect($stopAction)->toContain('$containerPresent = ! $removeContainers;')
        ->and($stopAction)->toMatch('/if \(\$server->isSwarm\(\)\).*?\$containerPresent = false;.*?docker stack rm/s')
        ->and($stopAction)->toContain("'container_present' => \$containerPresent")
        ->and($dockerStatus)->toContain("'container_present' => true")
        ->and($dockerStatus)->toContain("'container_present' => false")
        ->and($sentinelStatus)->toContain("'container_present' => true")
        ->and($sentinelStatus)->toContain("'container_present' => false");
});
