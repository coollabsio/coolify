<?php

use App\Jobs\CleanupInstanceStuffsJob;
use App\Models\InstanceSettings;
use App\Models\NotificationThrottle;
use App\Models\ScheduledDatabaseBackup;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Server\HighDiskUsage;
use App\Notifications\Server\Unreachable;
use Carbon\Carbon;
use Illuminate\Contracts\Notifications\Dispatcher as NotificationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('claims a notification only once until it is released', function () {
    expect(NotificationThrottle::claim($this->server, Unreachable::class))->toBeTrue()
        ->and(NotificationThrottle::claim($this->server, Unreachable::class))->toBeFalse()
        ->and($this->server->unreachable_notification_sent)->toBeTrue()
        ->and(NotificationThrottle::release($this->server, Unreachable::class))->toBeTrue()
        ->and(NotificationThrottle::release($this->server, Unreachable::class))->toBeFalse()
        ->and($this->server->unreachable_notification_sent)->toBeFalse()
        ->and(NotificationThrottle::claim($this->server, Unreachable::class))->toBeTrue();
});

it('claims a notification again only when it was sent before the given time', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-29 12:00:00', 'UTC'));

    expect(NotificationThrottle::claim($this->server, HighDiskUsage::class, now()->subHours(6)))->toBeTrue();

    Carbon::setTestNow(now()->addHours(5));
    expect(NotificationThrottle::claim($this->server, HighDiskUsage::class, now()->subHours(6)))->toBeFalse();

    Carbon::setTestNow(now()->addHours(2));
    expect(NotificationThrottle::claim($this->server, HighDiskUsage::class, now()->subHours(6)))->toBeTrue()
        ->and(NotificationThrottle::query()->count())->toBe(1);
});

it('keeps throttles separate for each notification and resource', function () {
    $otherServer = Server::factory()->create(['team_id' => $this->team->id]);

    NotificationThrottle::record($this->server, Unreachable::class);

    expect(NotificationThrottle::claim($this->server, HighDiskUsage::class))->toBeTrue()
        ->and(NotificationThrottle::claim($otherServer, Unreachable::class))->toBeTrue()
        ->and(NotificationThrottle::claim($this->server, Unreachable::class))->toBeFalse();
});

it('removes the throttles of a server when the server is force deleted', function () {
    NotificationThrottle::record($this->server, Unreachable::class);

    $this->server->forceDelete();

    expect(NotificationThrottle::query()->count())->toBe(0);
});

it('keeps unreachable_notification_sent in the server API response', function () {
    config()->set('app.maintenance.driver', 'file');
    config()->set('cache.default', 'array');
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);
    $user = User::factory()->create();
    $this->team->members()->attach($user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);
    $token = $user->createToken('read-token', ['read']);
    $token->accessToken->forceFill(['team_id' => $this->team->id])->save();

    NotificationThrottle::record($this->server, Unreachable::class);

    $this->withHeaders(['Authorization' => 'Bearer '.$token->plainTextToken])
        ->getJson('/api/v1/servers/'.$this->server->uuid)
        ->assertOk()
        ->assertJsonPath('unreachable_notification_sent', true);
});

function throttledDiskUsageNotification(Server $server): HighDiskUsage
{
    return new HighDiskUsage($server, 95, 80);
}

it('releases the throttle claim when sending the notification throws', function () {
    $sends = 0;
    $this->mock(NotificationDispatcher::class, function ($mock) use (&$sends) {
        $mock->shouldReceive('send')->twice()->andReturnUsing(function () use (&$sends) {
            $sends++;
            if ($sends === 1) {
                throw new RuntimeException('SMTP is down');
            }
        });
    });

    expect(fn () => $this->team->notify(throttledDiskUsageNotification($this->server)))
        ->toThrow(RuntimeException::class, 'SMTP is down')
        ->and(NotificationThrottle::wasSent($this->server, HighDiskUsage::class))->toBeFalse();

    $this->team->notify(throttledDiskUsageNotification($this->server));

    expect($sends)->toBe(2)
        ->and(NotificationThrottle::wasSent($this->server, HighDiskUsage::class))->toBeTrue();
});

it('throttles the notification after it was sent successfully', function () {
    $this->mock(NotificationDispatcher::class, fn ($mock) => $mock->shouldReceive('send')->once());

    $this->team->notify(throttledDiskUsageNotification($this->server));
    $this->team->notify(throttledDiskUsageNotification($this->server));

    expect(NotificationThrottle::wasSent($this->server, HighDiskUsage::class))->toBeTrue();
});

it('does not send a notification that another worker is already sending', function () {
    $notification = throttledDiskUsageNotification($this->server);
    $concurrentClaim = null;
    $this->mock(NotificationDispatcher::class, function ($mock) use ($notification, &$concurrentClaim) {
        $mock->shouldReceive('send')->once()->andReturnUsing(function () use ($notification, &$concurrentClaim) {
            $concurrentClaim = NotificationThrottle::claimFor($notification);
        });
    });

    $this->team->notify($notification);

    expect($concurrentClaim)->toBeFalse();
});

it('releases the unreachable claim when sending the unreachable notification throws', function () {
    $this->mock(NotificationDispatcher::class, fn ($mock) => $mock->shouldReceive('send')->once()->andThrow(new RuntimeException('Discord is down')));

    expect(fn () => $this->server->sendUnreachableNotification())->toThrow(RuntimeException::class)
        ->and(NotificationThrottle::wasSent($this->server, Unreachable::class))->toBeFalse();
});

it('deletes throttle rows whose subject no longer exists', function () {
    $trashedServer = Server::factory()->create(['team_id' => $this->team->id]);
    $trashedServer->delete();
    $rows = [
        'kept' => ['notifiable_type' => $this->server->getMorphClass(), 'notifiable_id' => $this->server->id],
        'kept-soft-deleted' => ['notifiable_type' => $trashedServer->getMorphClass(), 'notifiable_id' => $trashedServer->id],
        'missing-server' => ['notifiable_type' => $this->server->getMorphClass(), 'notifiable_id' => 999999],
        'missing-backup' => ['notifiable_type' => ScheduledDatabaseBackup::class, 'notifiable_id' => 999999],
        'missing-class' => ['notifiable_type' => 'App\Models\RemovedModel', 'notifiable_id' => 1],
    ];
    foreach ($rows as $notification => $row) {
        NotificationThrottle::query()->insert($row + ['notification' => $notification, 'sent_at' => now()]);
    }

    expect(NotificationThrottle::deleteOrphans())->toBe(3)
        ->and(NotificationThrottle::query()->orderBy('notification')->pluck('notification')->all())->toBe(['kept', 'kept-soft-deleted']);
});

it('deletes orphaned throttle rows during the instance cleanup', function () {
    InstanceSettings::query()->firstOrCreate(['id' => 0]);
    NotificationThrottle::record($this->server, Unreachable::class);
    NotificationThrottle::query()->insert([
        'notifiable_type' => ScheduledDatabaseBackup::class,
        'notifiable_id' => 999999,
        'notification' => 'App\Notifications\Database\BackupMissing',
        'sent_at' => now(),
    ]);

    (new CleanupInstanceStuffsJob)->handle();

    expect(NotificationThrottle::query()->pluck('notifiable_type')->all())->toBe([$this->server->getMorphClass()]);
});
