<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServerSetting extends Model
{
    protected $fillable = [
        'server_id'
    ];
    public function server()
    {
        return $this->belongsTo(Server::class);
    }
}
