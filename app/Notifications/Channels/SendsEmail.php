<?php

namespace App\Notifications\Channels;

interface SendsEmail
{
    public function getRecepients($notification);
}
