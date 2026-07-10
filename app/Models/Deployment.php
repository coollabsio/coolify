<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Deployment extends Model
{
    protected $fillable = [
        'project_id',
        'server_id',
        'source_id',
        'status',
        'logs',
    ];
}
