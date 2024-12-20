<?php

namespace App\Interfaces\Notifications;

interface SendsEmail
{
    public function getRecipients($notification);
}
