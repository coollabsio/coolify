<?php

namespace App\Models;

use App\Notifications\Channels\SendsEmail;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Spatie\SchemalessAttributes\Casts\SchemalessAttributes;
use Spatie\SchemalessAttributes\SchemalessAttributesTrait;

class InstanceSettings extends Model implements SendsEmail
{
    use Notifiable, SchemalessAttributesTrait;
    protected $guarded = [];
    protected $schemalessAttributes = [
        'smtp',
    ];
    protected $casts = [
        'smtp' => SchemalessAttributes::class,
    ];
    public function scopeWithSmtp(): Builder
    {
        return $this->smtp->modelScope();
    }
    public function routeNotificationForEmail(string $attribute = 'test_recipients')
    {
        $recipients = $this->smtp->get($attribute, '');
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
