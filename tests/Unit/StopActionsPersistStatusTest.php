<?php

it('persists exited status when stopping standalone databases', function () {
    $action = file_get_contents(__DIR__.'/../../app/Actions/Database/StopDatabase.php');

    expect($action)->toContain("'status' => 'exited'");
});

it('persists exited status for every full application stop path', function () {
    $action = file_get_contents(__DIR__.'/../../app/Actions/Application/StopApplication.php');

    expect($action)
        ->toContain("\$status = ['status' => 'exited'];")
        ->toContain('$application->update($status);')
        ->not->toMatch('/docker stack rm .*?return;/s');
});

it('persists exited status for all children when stopping a service', function () {
    $action = file_get_contents(__DIR__.'/../../app/Actions/Service/StopService.php');

    expect($action)
        ->toContain("\$applications->each->update(['status' => 'exited']);")
        ->toContain("\$dbs->each->update(['status' => 'exited']);");
});

it('persists exited status when stopping an individual service resource', function () {
    $action = file_get_contents(__DIR__.'/../../app/Actions/Service/StopServiceApplication.php');

    expect($action)
        ->toContain("\$serviceApplication->update(['status' => 'exited']);")
        ->toContain('ServiceStatusChanged::dispatch($service->environment->project->team->id);');
});

it('persists exited status when stopping a preview deployment', function () {
    $component = file_get_contents(__DIR__.'/../../app/Livewire/Project/Application/Previews.php');

    expect($component)
        ->toContain("->update(['status' => 'exited']);")
        ->toContain('ServiceStatusChanged::dispatch($this->application->environment->project->team->id);');
});
