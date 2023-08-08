<?php

namespace App\Models;

use App\Notifications\Channels\SendsEmail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

class InstanceSettings extends Model implements SendsEmail
{
    use Notifiable;

    protected $guarded = [];
    protected $casts = [
        'resale_license' => 'encrypted',
    ];

    public static function get()
    {
        return InstanceSettings::findOrFail(0);
    }

    public function getRecepients($notification)
    {
        $recipients = data_get($notification, 'emails', null);
        if (is_null($recipients) || $recipients === '') {
            return [];
        }
        return explode(',', $recipients);
    }
}
