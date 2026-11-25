<?php

namespace App\Notifications\Channels;

interface SendsSlack
{
    public function routeNotificationForSlack();
}
