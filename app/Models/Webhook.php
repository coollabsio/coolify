<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Webhook extends Model
{
    protected $guarded = [];

    protected $casts = [
        'type' => 'string',
        'payload' => 'encrypted',
    ];
}
