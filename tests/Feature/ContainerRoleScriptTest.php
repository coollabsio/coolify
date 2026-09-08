<?php

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

it('matches comma separated container roles and lets all override every service', function (string $roles, string $serviceRole) {
    $result = runContainerRoleHelper($roles, $serviceRole);

    expect($result->successful())->toBeTrue();
})->with([
    ['worker,flux', 'worker'],
    ['worker,flux', 'flux'],
    ['web, all', 'flux'],
    ['flux,all,worker', 'worker'],
    ['horizon,scheduler,nightwatch,flux', 'horizon'],
    ['horizon,scheduler,nightwatch,flux', 'scheduler'],
    ['horizon,scheduler,nightwatch,flux', 'nightwatch'],
]);

it('rejects services missing from the comma separated container roles', function () {
    $result = runContainerRoleHelper('web,flux', 'worker');

    expect($result->failed())->toBeTrue();
});

it('falls back to the container role from the local env file', function () {
    $directory = sys_get_temp_dir().'/coolify-container-role-'.bin2hex(random_bytes(4));
    mkdir($directory);
    file_put_contents($directory.'/.env', 'COOLIFY_CONTAINER_ROLE=worker,flux'.PHP_EOL);

    $result = runContainerRoleHelper('', 'flux', $directory);

    expect($result->successful())->toBeTrue();
});

it('keeps container roles out of the production image', function () {
    expect(base_path('docker/production/etc/s6-overlay/scripts/container-role'))->not->toBeFile();

    foreach (['horizon', 'nightwatch-agent', 'scheduler-worker'] as $service) {
        $runScript = file_get_contents(base_path("docker/production/etc/s6-overlay/s6-rc.d/{$service}/run"));

        expect($runScript)
            ->not->toContain('container-role')
            ->not->toContain('COOLIFY_CONTAINER_ROLE');
    }
});

function runContainerRoleHelper(string $roles, string $serviceRole, ?string $workingDirectory = null): ProcessResult
{
    $script = base_path('docker/development/etc/s6-overlay/scripts/container-role');
    $command = sprintf(
        '. %s && coolify_container_has_role %s',
        escapeshellarg($script),
        escapeshellarg($serviceRole),
    );

    return Process::path($workingDirectory ?? base_path())
        ->env(['COOLIFY_CONTAINER_ROLE' => $roles])
        ->run($command);
}
