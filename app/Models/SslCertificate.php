<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SslCertificate extends Model
{
    protected $fillable = [
        'ssl_certificate',
        'ssl_private_key',
        'resource_type',
        'resource_id',
        'server_id',
        'valid_until',
    ];

    protected $casts = [
        'ssl_certificate' => 'encrypted',
        'ssl_private_key' => 'encrypted',
        'valid_until' => 'datetime',
    ];

    public function resource()
    {
        return $this->morphTo();
    }

    public function server()
    {
        return $this->belongsTo(Server::class);
    }
}
