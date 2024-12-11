<?php

namespace App\Notifications\Channels;

use App\Jobs\SendMessageToTelegramJob;

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

        $topicId = match (get_class($notification)) {
            \App\Notifications\Test::class => $settings->telegram_notifications_test_topic_id,
            \App\Notifications\Application\StatusChanged::class,
            \App\Notifications\Container\ContainerRestarted::class,
            \App\Notifications\Container\ContainerStopped::class => $settings->telegram_notifications_status_change_topic_id,
            \App\Notifications\Application\DeploymentSuccess::class => $settings->telegram_notifications_deployment_success_topic_id,
            \App\Notifications\Application\DeploymentFailed::class => $settings->telegram_notifications_deployment_failure_topic_id,
            \App\Notifications\Database\BackupSuccess::class => $settings->telegram_notifications_backup_success_topic_id,
            \App\Notifications\Database\BackupFailed::class => $settings->telegram_notifications_backup_failure_topic_id,
            \App\Notifications\ScheduledTask\TaskFailed::class => $settings->telegram_notifications_scheduled_task_failure_topic_id,
            \App\Notifications\Server\Unreachable::class => $settings->telegram_notifications_server_unreachable_topic_id,
            \App\Notifications\Server\Reachable::class => $settings->telegram_notifications_server_reachable_topic_id,
            default => null,
        };

        if (! $telegramToken || ! $chatId || ! $message) {
            return;
        }

        SendMessageToTelegramJob::dispatch($message, $buttons, $telegramToken, $chatId, $topicId);
    }
}
