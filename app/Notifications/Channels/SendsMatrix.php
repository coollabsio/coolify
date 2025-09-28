<?php

namespace App\Notifications\Channels;

interface SendsMatrix
{
    public function routeNotificationForMatrix(): mixed;
}