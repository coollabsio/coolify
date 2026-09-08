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

it('shows accurate destructive actions and the restart warning on mobile', function () {
    $heading = file_get_contents(resource_path('views/livewire/project/application/heading.blade.php'));
    $mobileActions = str($heading)
        ->after('id="application-mobile-actions"')
        ->before('<div class="hidden" aria-hidden="true">')
        ->toString();

    expect($heading)->toContain('<x-application.restart-limit-warning :application="$application" />')
        ->and(substr_count($heading, '$application->container_present !== false'))->toBe(2)
        ->and($heading)->toContain("\$application->stoppedAfterRestartLimit() ? 'Retry deployment' : 'Deploy'")
        ->and($heading)->toContain("\$application->stoppedAfterRestartLimit() ? 'Retry deployment (without cache)' : 'Deploy (without cache)'")
        ->and($heading)->toContain('Remove container')
        ->and($mobileActions)->toContain('Deploy (without cache)')
        ->and($mobileActions)->toContain('Remove container')
        ->and(strrpos($mobileActions, 'Remove container'))->toBeGreaterThan(strrpos($mobileActions, 'Deploy (without cache)'));
});

it('shows restart limit reached as a yellow state in environment resource lists', function () {
    $indexClass = file_get_contents(app_path('Livewire/Project/Resource/Index.php'));
    $indexView = file_get_contents(resource_path('views/livewire/project/resource/index.blade.php'));

    expect($indexClass)->toContain("'restartLimitReached' => \$type === 'application' && \$item->stoppedAfterRestartLimit()")
        ->and($indexClass)->toContain('? max($item->restart_count ?? 0, $item->max_restart_count ?? 0)')
        ->and($indexClass)->toContain("'maxRestartCount' => \$item->max_restart_count ?? 0")
        ->and($indexView)->toContain("if (item.restartLimitReached) {\n                    return 'restart-limit';")
        ->and($indexView)->toContain("return 'Restart limit reached';")
        ->and($indexView)->toContain("if (item.restartLimitReached) {\n                    return 'bg-warning';")
        ->and($indexView)->toContain('x-bind:title="statusTitle(item)"');
});
