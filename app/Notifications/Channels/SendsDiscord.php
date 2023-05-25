<?php

namespace App\Notifications\Channels;

interface SendsDiscord
{
    public function routeNotificationForDiscord();
}
