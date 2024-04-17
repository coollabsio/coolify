<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class SharedEnvironmentVariable extends Model
{
    protected $guarded = [];
    protected $casts = [
        'key' => 'string',
        'value' => 'encrypted',
    ];
}
