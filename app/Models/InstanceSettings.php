<?php

namespace App\Models;

use App\Notifications\Channels\SendsEmail;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Spatie\SchemalessAttributes\Casts\SchemalessAttributes;

class InstanceSettings extends Model implements SendsEmail
{
    use Notifiable;
    protected $guarded = [];
    protected $casts = [
        'resale_license' => 'encrypted',
    ];
    public function routeNotificationForEmail(string $attribute = 'test_recipients')
    {
        $recipients = data_get($this,'smtp','');
        if (is_null($recipients) || $recipients === '') {
            return [];
        }
        return explode(',', $recipients);
    }
    public static function get()
    {
        return InstanceSettings::findOrFail(0);
    }
}