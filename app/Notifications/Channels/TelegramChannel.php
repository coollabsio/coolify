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
        ray($message, $buttons);
        $telegramToken = data_get($telegramData, 'token');
        $chatId = data_get($telegramData, 'chat_id');

        if (!$telegramToken || !$chatId || !$message) {
            throw new \Exception('Telegram token, chat id and message are required');
        }
        dispatch(new SendMessageToTelegramJob($message, $buttons, $telegramToken, $chatId));
    }
}
