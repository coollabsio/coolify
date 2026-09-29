<?php

use App\Models\InstanceSettings;
use App\Models\NotificationThrottle;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Server\HighDiskUsage;
use App\Notifications\Server\Unreachable;
use Carbon\Carbon;
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
