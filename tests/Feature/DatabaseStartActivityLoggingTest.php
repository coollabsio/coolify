<?php

use App\Actions\Database\StartDatabase;
use App\Jobs\DatabaseStartJob;
use App\Models\Server;
use App\Models\ServerSetting;
use App\Models\StandaloneDocker;
use App\Models\StandaloneRedis;
use Illuminate\Support\Facades\Bus;
use Spatie\Activitylog\ActivityLogStatus;

it('returns an actionable error when database start activity logging is disabled', function () {
    config()->set('activitylog.enabled', false);
    app(ActivityLogStatus::class)->disable();
    Bus::fake();

    $server = new Server(['ip' => '192.0.2.1']);
    $server->setRelation('settings', new ServerSetting([
        'is_reachable' => true,
        'is_usable' => true,
        'force_disabled' => false,
    ]));

    $destination = new StandaloneDocker;
    $destination->setRelation('server', $server);

    $database = new StandaloneRedis;
    $database->setRelation('destination', $destination);

    $result = (new StartDatabase)->handle($database);

    expect($result)->toBe('Database start could not be queued because activity logging is disabled.');
    Bus::assertNotDispatched(DatabaseStartJob::class);
});
