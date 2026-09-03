<?php

use App\Jobs\ScheduledTaskJob;

it('ignores exited application containers left behind by a rolling deploy', function () {
    $names = ScheduledTaskJob::runningContainerNames([
        ['Names' => '/appuuid-072221650268', 'State' => 'exited'],
        ['Names' => '/appuuid-072221655111', 'State' => 'running'],
    ]);

    expect($names)->toBe(['appuuid-072221655111']);
});

it('picks the oldest running application container when no name is configured', function () {
    $picked = ScheduledTaskJob::pickScheduledTaskContainer(
        ['appuuid-072221655111', 'appuuid-072221650268'],
        null,
        'appuuid',
        true,
    );

    expect($picked)->toBe('appuuid-072221650268');
});

it('prefers a consistent application container name over timestamped rolling names', function () {
    $picked = ScheduledTaskJob::pickScheduledTaskContainer(
        ['appuuid-072221655111', 'appuuid'],
        null,
        'appuuid',
        true,
    );

    expect($picked)->toBe('appuuid');
});

it('matches an application container by uuid prefix so the UI field works', function () {
    expect(ScheduledTaskJob::containerMatchesScheduledTask(
        'appuuid-072221650268',
        'appuuid',
        'appuuid',
        true,
    ))->toBeTrue()
        ->and(ScheduledTaskJob::containerMatchesScheduledTask(
            'appuuid-072221650268',
            'appuuid-072221650268',
            'appuuid',
            true,
        ))->toBeTrue();
});

it('still requires a container name when a service has multiple running containers', function () {
    $picked = ScheduledTaskJob::pickScheduledTaskContainer(
        ['web-serviceuuid', 'db-serviceuuid'],
        null,
        'serviceuuid',
        false,
    );

    expect($picked)->toBeNull();
});

it('matches a service container using name-uuid like the previous matcher', function () {
    expect(ScheduledTaskJob::containerMatchesScheduledTask(
        'web-serviceuuid',
        'web',
        'serviceuuid',
        false,
    ))->toBeTrue()
        ->and(ScheduledTaskJob::pickScheduledTaskContainer(
            ['web-serviceuuid', 'db-serviceuuid'],
            'web',
            'serviceuuid',
            false,
        ))->toBe('web-serviceuuid');
});
