<?php

namespace App\Notifications\Channels;

interface SendsPushover
{
    public function routeNotificationForPushover();
}
