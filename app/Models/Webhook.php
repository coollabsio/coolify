<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Webhook extends Model
{
    protected $guarded = [];
    protected $casts = [
        'payload' => 'encrypted',
    ];
}