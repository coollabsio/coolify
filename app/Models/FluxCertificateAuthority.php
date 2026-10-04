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

    /** Created for a rotation and trusted by Nodes, but not yet signing Flux leaves. */
    public const STATE_PENDING = 'pending';

    /** Signs the Flux leaf. */
    public const STATE_ACTIVE = 'active';

    /** Replaced by a rotated CA and still trusted until retirement. */
    public const STATE_SUPERSEDED = 'superseded';

    /** Removed from the trust bundle. The row stays for audit; the key is erased. */
    public const STATE_RETIRED = 'retired';

    /** A cancelled rotation's CA. It never signed a leaf; the key is erased. */
    public const STATE_DISCARDED = 'discarded';

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
