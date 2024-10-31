<?php

namespace App\Notifications\Channels;

use App\Jobs\SendMessageToTelegramJob;

class TelegramChannel
{
    public function send($notifiable, $notification): void
    {
        $data = $notification->toTelegram($notifiable);
        $telegramData = $notifiable->routeNotificationForTelegram();
        $message = data_get($data, 'message');
        $buttons = data_get($data, 'buttons', []);
        $telegramToken = data_get($telegramData, 'token');
        $chatId = data_get($telegramData, 'chat_id');
        $topicId = null;
        $topicsInstance = get_class($notification);

        switch ($topicsInstance) {
            case \App\Notifications\Test::class:
                $topicId = data_get($notifiable, 'telegram_notifications_test_message_thread_id');
                break;
            case \App\Notifications\Application\StatusChanged::class:
            case \App\Notifications\Container\ContainerRestarted::class:
            case \App\Notifications\Container\ContainerStopped::class:
                $topicId = data_get($notifiable, 'telegram_notifications_status_changes_message_thread_id');
                break;
            case \App\Notifications\Application\DeploymentSuccess::class:
            case \App\Notifications\Application\DeploymentFailed::class:
                $topicId = data_get($notifiable, 'telegram_notifications_deployments_message_thread_id');
                break;
            case \App\Notifications\Database\BackupSuccess::class:
            case \App\Notifications\Database\BackupFailed::class:
                $topicId = data_get($notifiable, 'telegram_notifications_database_backups_message_thread_id');
                break;
            case \App\Notifications\ScheduledTask\TaskFailed::class:
                $topicId = data_get($notifiable, 'telegram_notifications_scheduled_tasks_thread_id');
                break;
        }
        if (! $telegramToken || ! $chatId || ! $message) {
            return;
        }
        dispatch(new SendMessageToTelegramJob($message, $buttons, $telegramToken, $chatId, $topicId));
    }
}
