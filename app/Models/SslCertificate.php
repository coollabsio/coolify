<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SslCertificate extends Model
{
    protected $fillable = [
        'ssl_certificate',
        'ssl_private_key',
        'cert_file_path',
        'key_file_path',
        'resource_type',
        'resource_id',
        'mount_path',
        'host_path',
        'certificate_type',
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
}
