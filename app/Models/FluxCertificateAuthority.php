<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class FluxCertificateAuthority extends BaseModel
{
    protected $fillable = [
        'version',
        'certificate_pem',
        'private_key_pem',
        'fingerprint',
        'serial_number',
        'valid_from',
        'valid_until',
        'state',
    ];

    protected $hidden = ['private_key_pem'];

    protected $casts = [
        'version' => 'integer',
        'private_key_pem' => 'encrypted',
        'valid_from' => 'immutable_datetime',
        'valid_until' => 'immutable_datetime',
    ];

    public function certificates(): HasMany
    {
        return $this->hasMany(FluxCertificate::class, 'certificate_authority_id');
    }
}
