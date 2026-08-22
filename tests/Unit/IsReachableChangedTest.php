<?php

use App\Models\Server;

afterEach(function () {
    Mockery::close();
});

function makeServerForReachabilityTest(bool $isReachable, bool $notificationSent, int $unreachableCount): Server
{
    $settings = Mockery::mock();
    $settings->is_reachable = $isReachable;

    $server = Mockery::mock(Server::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $server->shouldReceive('refresh')->andReturnSelf();
    $server->shouldReceive('getAttribute')->with('settings')->andReturn($settings);
    $server->shouldReceive('getAttribute')->with('unreachable_notification_sent')->andReturn($notificationSent);
    $server->shouldReceive('getAttribute')->with('unreachable_count')->andReturn($unreachableCount);

    return $server;
}

it('sends Reachable notification when reachable and notification was previously sent', function () {
    $server = makeServerForReachabilityTest(isReachable: true, notificationSent: true, unreachableCount: 0);
    $server->shouldReceive('sendReachableNotification')->once();
    $server->shouldNotReceive('sendUnreachableNotification');

    $server->isReachableChanged();
});

it('does not send any notification when reachable and notification was never sent', function () {
    $server = makeServerForReachabilityTest(isReachable: true, notificationSent: false, unreachableCount: 0);
    $server->shouldNotReceive('sendReachableNotification');
    $server->shouldNotReceive('sendUnreachableNotification');

    $server->isReachableChanged();
});

it('sends Unreachable notification when count >= 2 and not yet notified', function () {
    $server = makeServerForReachabilityTest(isReachable: false, notificationSent: false, unreachableCount: 2);
    $server->shouldReceive('sendUnreachableNotification')->once();
    $server->shouldNotReceive('sendReachableNotification');

    $server->isReachableChanged();
});

it('does not send Unreachable notification on first transient failure (count=1)', function () {
    $server = makeServerForReachabilityTest(isReachable: false, notificationSent: false, unreachableCount: 1);
    $server->shouldNotReceive('sendUnreachableNotification');
    $server->shouldNotReceive('sendReachableNotification');

    $server->isReachableChanged();
});

it('does not double-send Unreachable when already notified', function () {
    $server = makeServerForReachabilityTest(isReachable: false, notificationSent: true, unreachableCount: 5);
    $server->shouldNotReceive('sendUnreachableNotification');
    $server->shouldNotReceive('sendReachableNotification');

    $server->isReachableChanged();
});
