<?php

use App\Models\Application;
use App\Models\InstanceSettings;
use App\Notifications\Application\RestartLimitReached;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function restartLimitApplication(): Application
{
    $application = new Application;
    $application->forceFill([
        'name' => 'crashy-app',
        'uuid' => 'application-uuid',
        'restart_count' => 2,
        'max_restart_count' => 2,
    ]);
    $application->setRelation('environment', (object) [
        'uuid' => 'environment-uuid',
        'name' => 'production',
        'project' => (object) ['uuid' => 'project-uuid'],
    ]);

    return $application;
}

it('uses the instance fqdn for restart limit notification urls', function () {
    InstanceSettings::forceCreate(['id' => 0, 'fqdn' => 'https://coolify.example.com']);

    $notification = new RestartLimitReached(restartLimitApplication());

    expect($notification->resource_url)
        ->toBe('https://coolify.example.com/project/project-uuid/environment/environment-uuid/application/application-uuid');
});

it('falls back to the app url when no instance fqdn is configured', function () {
    InstanceSettings::forceCreate(['id' => 0]);

    $notification = new RestartLimitReached(restartLimitApplication());

    expect($notification->resource_url)
        ->toBe(config('app.url').'/project/project-uuid/environment/environment-uuid/application/application-uuid');
});
