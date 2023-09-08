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
        $topicId  = null;
        $topicsInstance = get_class($notification);

        switch ($topicsInstance) {
            case 'App\Notifications\StatusChange':
                $topicId = data_get($notifiable, 'telegram_notifications_status_changes_message_thread_id');
                break;
            case 'App\Notifications\Test':
                $topicId = data_get($notifiable, 'telegram_notifications_test_message_thread_id');
                break;
            case 'App\Notifications\Deployment':
                $topicId = data_get($notifiable, 'telegram_notifications_deployments_message_thread_id');
                break;
            case 'App\Notifications\DatabaseBackup':
                $topicId = data_get($notifiable, 'telegram_notifications_database_backups_message_thread_id');
                break;
        }
        if (!$telegramToken || !$chatId || !$message) {
            throw new \Exception('Telegram token, chat id and message are required');
        }
        dispatch(new SendMessageToTelegramJob($message, $buttons, $telegramToken, $chatId, $topicId));
    }
}
