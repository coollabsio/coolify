<?php

namespace App\Notifications\Channels;

use App\Jobs\SendMessageToTelegramJob;
use App\Notifications\Application\DeploymentFailed;
use App\Notifications\Application\DeploymentSuccess;
use App\Notifications\Application\StatusChanged;
use App\Notifications\Container\ContainerRestarted;
use App\Notifications\Container\ContainerStopped;
use App\Notifications\Database\BackupFailed;
use App\Notifications\Database\BackupSuccess;
use App\Notifications\ScheduledTask\TaskFailed;
use App\Notifications\ScheduledTask\TaskSuccess;
use App\Notifications\Server\DockerCleanupFailed;
use App\Notifications\Server\DockerCleanupSuccess;
use App\Notifications\Server\HighDiskUsage;
use App\Notifications\Server\Reachable;
use App\Notifications\Server\Unreachable;

class TelegramChannel
{
    public function send($notifiable, $notification): void
    {
        $data = $notification->toTelegram($notifiable);
        $settings = $notifiable->telegramNotificationSettings;

        $message = data_get($data, 'message');
        $buttons = data_get($data, 'buttons', []);
        $telegramToken = $settings->telegram_token;
        $chatId = $settings->telegram_chat_id;

        $threadId = match (get_class($notification)) {
            DeploymentSuccess::class => $settings->telegram_notifications_deployment_success_thread_id,
            DeploymentFailed::class => $settings->telegram_notifications_deployment_failure_thread_id,
            StatusChanged::class,
            ContainerRestarted::class,
            ContainerStopped::class => $settings->telegram_notifications_status_change_thread_id,

            BackupSuccess::class => $settings->telegram_notifications_backup_success_thread_id,
            BackupFailed::class => $settings->telegram_notifications_backup_failure_thread_id,

            TaskSuccess::class => $settings->telegram_notifications_scheduled_task_success_thread_id,
            TaskFailed::class => $settings->telegram_notifications_scheduled_task_failure_thread_id,

            DockerCleanupSuccess::class => $settings->telegram_notifications_docker_cleanup_success_thread_id,
            DockerCleanupFailed::class => $settings->telegram_notifications_docker_cleanup_failure_thread_id,
            HighDiskUsage::class => $settings->telegram_notifications_server_disk_usage_thread_id,
            Unreachable::class => $settings->telegram_notifications_server_unreachable_thread_id,
            Reachable::class => $settings->telegram_notifications_server_reachable_thread_id,

            default => null,
        };

        if (! $telegramToken || ! $chatId || ! $message) {
            return;
        }

        SendMessageToTelegramJob::dispatch($message, $buttons, $telegramToken, $chatId, $threadId);
    }
}
