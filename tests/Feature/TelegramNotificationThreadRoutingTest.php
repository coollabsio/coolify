<?php

use App\Jobs\SendMessageToTelegramJob;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\Server\ServerPatchCheck;
use App\Notifications\Server\TraefikVersionOutdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->firstOrCreate(['id' => 0]));

    $this->team = Team::factory()->create();
    $this->team->telegramNotificationSettings->update([
        'telegram_enabled' => true,
        'telegram_token' => 'test-token',
        'telegram_chat_id' => '-1001234567890',
    ]);

    Queue::fake();
});

function telegramTestServer(Team $team): Server
{
    return Server::factory()->make([
        'name' => 'Test Server',
        'uuid' => 'test-uuid',
        'team_id' => $team->id,
    ]);
}

function outdatedTraefikServer(Team $team): Server
{
    $server = telegramTestServer($team);

    $server->outdatedInfo = [
        'current' => '3.5.0',
        'latest' => '3.5.6',
        'type' => 'patch_update',
    ];

    return $server;
}

it('sends the traefik outdated notification to its configured topic', function () {
    $this->team->telegramNotificationSettings->update([
        'telegram_notifications_traefik_outdated_thread_id' => '42',
    ]);

    $notification = new TraefikVersionOutdated(collect([outdatedTraefikServer($this->team)]));

    (new TelegramChannel)->send($this->team->fresh(), $notification);

    Queue::assertPushed(
        SendMessageToTelegramJob::class,
        fn (SendMessageToTelegramJob $job) => $job->threadId === '42'
    );
});

it('sends the server patch notification to its configured topic', function () {
    $this->team->telegramNotificationSettings->update([
        'telegram_notifications_server_patch_thread_id' => '7',
    ]);

    $notification = new ServerPatchCheck(telegramTestServer($this->team), ['total_updates' => 3]);

    (new TelegramChannel)->send($this->team->fresh(), $notification);

    Queue::assertPushed(
        SendMessageToTelegramJob::class,
        fn (SendMessageToTelegramJob $job) => $job->threadId === '7'
    );
});

it('falls back to the main chat when no topic is configured', function () {
    $notification = new TraefikVersionOutdated(collect([outdatedTraefikServer($this->team)]));

    (new TelegramChannel)->send($this->team->fresh(), $notification);

    Queue::assertPushed(
        SendMessageToTelegramJob::class,
        fn (SendMessageToTelegramJob $job) => $job->threadId === null
    );
});
