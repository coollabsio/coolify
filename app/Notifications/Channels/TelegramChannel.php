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
            case 'App\Notifications\Test':
                $topicId = data_get($notifiable, 'telegram_notifications_test_message_thread_id');
                break;
            case 'App\Notifications\Application\StatusChanged':
            case 'App\Notifications\Container\ContainerRestarted':
            case 'App\Notifications\Container\ContainerStopped':
                $topicId = data_get($notifiable, 'telegram_notifications_status_changes_message_thread_id');
                break;
            case 'App\Notifications\Application\DeploymentSuccess':
            case 'App\Notifications\Application\DeploymentFailed':
                $topicId = data_get($notifiable, 'telegram_notifications_deployments_message_thread_id');
                break;
            case 'App\Notifications\Database\BackupSuccess':
            case 'App\Notifications\Database\BackupFailed':
                $topicId = data_get($notifiable, 'telegram_notifications_database_backups_message_thread_id');
                break;
            case 'App\Notifications\ScheduledTask\TaskFailed':
                $topicId = data_get($notifiable, 'telegram_notifications_scheduled_tasks_thread_id');
                break;
        }
        if (!$telegramToken || !$chatId || !$message) {
            return;
        }
        dispatch(new SendMessageToTelegramJob($message, $buttons, $telegramToken, $chatId, $topicId));
    }
}
