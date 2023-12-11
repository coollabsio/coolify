<?php

namespace App\Models;

use App\Notifications\Channels\SendsEmail;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Request;
use Spatie\Url\Url;

class InstanceSettings extends Model implements SendsEmail
{
    use Notifiable;

    protected $guarded = [];
    protected $casts = [
        'resale_license' => 'encrypted',
        'smtp_password' => 'encrypted',
    ];

    public function fqdn(): Attribute
    {
        return Attribute::make(
            set: function ($value) {
                if ($value) {
                    $url = Url::fromString($value);
                    $host = $url->getHost();
                    return $url->getScheme() . '://' . $host;
                }
            }
        );
    }
    public static function realtimePort() {
        $envDefined = env('PUSHER_PORT');
        if ($envDefined != '6001') {
            return $envDefined;
        }
        $url = Url::fromString(Request::getSchemeAndHttpHost());
        if ($url->getScheme() === 'https') {
            return 443;
        } else {
            return 6001;
        }
    }
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
