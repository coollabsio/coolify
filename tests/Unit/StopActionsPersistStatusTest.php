<?php

it('persists exited status when stopping standalone databases', function () {
    $action = file_get_contents(__DIR__.'/../../app/Actions/Database/StopDatabase.php');

    expect($action)->toContain("'status' => 'exited'");
});

it('does not change Docker restart policies when retaining stopped containers', function (string $actionPath) {
    $action = file_get_contents(__DIR__.'/../../'.$actionPath);

    expect($action)->not->toContain('docker update --restart=no');
})->with([
    'applications' => 'app/Actions/Application/StopApplication.php',
    'application previews' => 'app/Actions/Application/StopApplicationPreview.php',
    'service applications' => 'app/Actions/Service/StopServiceApplication.php',
    'standalone databases' => 'app/Actions/Database/StopDatabase.php',
]);

it('persists exited status for every full application stop path', function () {
    $action = file_get_contents(__DIR__.'/../../app/Actions/Application/StopApplication.php');

    expect($action)
        ->toMatch('/\$status\s*=\s*\[\s*\'status\'\s*=>\s*\'exited\',.*?\];.*?\$application->update\(\$status\);/s')
        ->not->toMatch('/docker stack rm .*?return;/s');
});

it('persists exited status for all children when stopping a service', function () {
    $action = file_get_contents(__DIR__.'/../../app/Actions/Service/StopService.php');

    expect($action)
        ->toContain("\$application->update(['status' => 'exited']);")
        ->toContain('$application->resetRestartLimit();')
        ->toContain("\$database->update(['status' => 'exited']);")
        ->not->toContain('$database->resetRestartLimit();');
});

it('persists exited status when stopping an individual service resource', function () {
    $action = file_get_contents(__DIR__.'/../../app/Actions/Service/StopServiceApplication.php');

    expect($action)
        ->toContain("\$serviceApplication->update(['status' => 'exited']);")
        ->toContain('$commands = ["docker rm -f {$containerName}"];')
        ->toContain('throwError: ! $removeContainer')
        ->toContain('ServiceStatusChanged::dispatch($service->environment->project->team->id);');
});

it('persists exited status when stopping a preview deployment', function () {
    $component = file_get_contents(__DIR__.'/../../app/Livewire/Project/Application/Previews.php');

    expect($component)
        ->toContain("->update(['status' => 'exited']);")
        ->toContain('ServiceStatusChanged::dispatch($this->application->environment->project->team->id);');
});
