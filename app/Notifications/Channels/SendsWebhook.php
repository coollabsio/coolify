<?php

namespace App\Notifications\Channels;

interface SendsWebhook
{
    public function routeNotificationForWebhook();
}
