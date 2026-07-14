<?php

use App\Actions\Application\StopApplication;
use App\Models\Application;
use App\Notifications\Application\RestartLimitReached;

function applicationWithRestartState(array $attributes = []): Application
{
    $application = new Application;
    $application->forceFill(array_merge([
        'status' => 'exited:unhealthy',
        'restart_count' => 2,
        'max_restart_count' => 2,
        'last_restart_type' => 'crash',
        'last_restart_at' => now(),
    ], $attributes));

    return $application;
}

it('detects applications stopped after reaching the crash restart limit', function () {
    expect(applicationWithRestartState()->stoppedAfterRestartLimit())->toBeTrue()
        ->and(applicationWithRestartState(['status' => 'running:unhealthy'])->stoppedAfterRestartLimit())->toBeFalse()
        ->and(applicationWithRestartState(['restart_count' => 1])->stoppedAfterRestartLimit())->toBeFalse()
        ->and(applicationWithRestartState(['max_restart_count' => 0])->stoppedAfterRestartLimit())->toBeFalse()
        ->and(applicationWithRestartState(['last_restart_type' => null])->stoppedAfterRestartLimit())->toBeFalse();
});

it('shows a stopped after restart limit warning in the status badge', function () {
    $html = view('components.status.index', [
        'resource' => applicationWithRestartState(),
        'showRefreshButton' => false,
    ])->render();

    expect($html)->toContain('Stopped after reaching restart limit (2/2).')
        ->and($html)->toContain('Container has crashed and Coolify stopped it after 2 restart attempts.');
});

it('does not show the restart limit warning for a normal manual stop', function () {
    $html = view('components.status.index', [
        'resource' => applicationWithRestartState([
            'restart_count' => 0,
            'last_restart_type' => null,
        ]),
        'showRefreshButton' => false,
    ])->render();

    expect($html)->not->toContain('Stopped after reaching restart limit');
});

it('keeps restart tracking configurable when stopping an application', function () {
    $method = new ReflectionMethod(StopApplication::class, 'handle');
    $resetRestartCount = collect($method->getParameters())->firstWhere('name', 'resetRestartCount');

    expect($resetRestartCount)->not->toBeNull()
        ->and($resetRestartCount->getDefaultValue())->toBeTrue();
});

it('uses the application link for restart limit notifications', function () {
    $application = new class extends Application
    {
        public function link()
        {
            return 'https://coolify.test/project/link-from-model';
        }
    };
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

    $notification = new RestartLimitReached($application);

    expect($notification->resource_url)->toBe('https://coolify.test/project/link-from-model');
});
