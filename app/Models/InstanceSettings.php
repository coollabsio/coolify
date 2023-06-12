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
    protected $casts = [
        'extra_attributes' => SchemalessAttributes::class,
    ];
    public function scopeWithExtraAttributes(): Builder
    {
        return $this->extra_attributes->modelScope();
    }
    public function routeNotificationForEmail(string $attribute = 'smtp_test_recipients')
    {
        $recipients = $this->extra_attributes->get($attribute, '');
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
