<?php

use App\Models\Application;

function applicationWithAdditionalServerStatuses(string $mainStatus, array $additionalStatuses): Application
{
    $application = new Application;
    $application->setRawAttributes(['status' => $mainStatus]);
    $application->setRelation('additional_servers', collect($additionalStatuses)
        ->map(fn (string $status) => (object) ['pivot' => (object) ['status' => $status]]));

    return $application;
}

it('stays healthy when an additional server status has no health segment', function () {
    expect(applicationWithAdditionalServerStatuses('running:healthy', ['running'])->status)
        ->toBe('running:healthy');
});

it('reports unhealthy when an additional server has an explicit unhealthy status', function () {
    expect(applicationWithAdditionalServerStatuses('running:healthy', ['running:unhealthy'])->status)
        ->toBe('running:unhealthy');
});

it('reports degraded when an additional server status without health differs', function () {
    expect(applicationWithAdditionalServerStatuses('running:healthy', ['exited'])->status)
        ->toBe('degraded:healthy');
});
