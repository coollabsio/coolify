<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FluxCertificate extends BaseModel
{
    protected $fillable = [
        'certificate_authority_id',
        'version',
        'certificate_pem',
        'private_key_pem',
        'fingerprint',
        'serial_number',
        'identities',
        'valid_from',
        'valid_until',
        'state',
    ];

    protected $hidden = ['private_key_pem'];

    protected $casts = [
        'version' => 'integer',
        'private_key_pem' => 'encrypted',
        'identities' => 'array',
        'valid_from' => 'immutable_datetime',
        'valid_until' => 'immutable_datetime',
    ];

    public function certificateAuthority(): BelongsTo
    {
        return $this->belongsTo(FluxCertificateAuthority::class, 'certificate_authority_id');
    }
}
