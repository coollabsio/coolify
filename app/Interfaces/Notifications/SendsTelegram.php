<?php

namespace App\Interfaces\Notifications;

interface SendsTelegram
{
    public function routeNotificationForTelegram();
}
