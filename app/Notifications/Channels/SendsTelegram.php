<?php

namespace App\Notifications\Channels;

interface SendsTelegram
{
    public function routeNotificationForTelegram();
}
