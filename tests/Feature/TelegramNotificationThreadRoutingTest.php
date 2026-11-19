<?php

use App\Jobs\SendMessageToTelegramJob;
use App\Models\InstanceSettings;
use App\Models\ScheduledVolumeBackup;
use App\Models\Server;
use App\Models\Team;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\Server\ServerPatchCheck;
use App\Notifications\Server\TraefikVersionOutdated;
use App\Notifications\VolumeBackup\BackupFailed;
use App\Notifications\VolumeBackup\BackupSuccess;
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

it('sends volume backup notifications to the backup topics', function (Closure $makeNotification, string $threadId) {
    $this->team->telegramNotificationSettings->update([
        'telegram_notifications_backup_success_thread_id' => '11',
        'telegram_notifications_backup_failure_thread_id' => '12',
    ]);
    $backup = new ScheduledVolumeBackup(['frequency' => 'daily']);

    (new TelegramChannel)->send($this->team->fresh(), $makeNotification($backup));

    Queue::assertPushed(
        SendMessageToTelegramJob::class,
        fn (SendMessageToTelegramJob $job) => $job->threadId === $threadId
    );
})->with([
    'success' => [fn (ScheduledVolumeBackup $backup) => new BackupSuccess($backup), '11'],
    'success with warning' => [fn (ScheduledVolumeBackup $backup) => new BackupSuccess($backup, 'S3 upload failed'), '12'],
    'failed' => [fn (ScheduledVolumeBackup $backup) => new BackupFailed($backup, 'Archive command failed'), '12'],
]);
