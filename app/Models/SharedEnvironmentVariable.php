<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SharedEnvironmentVariable extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'key' => 'string',
            'value' => 'encrypted',
        ];
    }
}
