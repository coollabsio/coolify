<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Spatie\SchemalessAttributes\Casts\SchemalessAttributes;

class Server extends BaseModel
{
    protected static function booted()
    {
        static::created(function ($server) {
            ServerSetting::create([
                'server_id' => $server->id,
            ]);
        });
    }

    public $casts = [
        'extra_attributes' => SchemalessAttributes::class,
    ];

    public function scopeWithExtraAttributes(): Builder
    {
        return $this->extra_attributes->modelScope();
    }

    public function privateKey()
    {
        return $this->belongsTo(PrivateKey::class);
    }

    public function settings()
    {
        return $this->hasOne(ServerSetting::class);
    }
}
