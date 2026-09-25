<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Links a managed DNS record to one resource (application, preview or service application) that uses its hostname.
 */
class ManagedDnsRecordReference extends Model
{
    protected $fillable = ['managed_dns_record_id', 'resource_type', 'resource_id'];

    public function record(): BelongsTo
    {
        return $this->belongsTo(ManagedDnsRecord::class, 'managed_dns_record_id');
    }

    public function resource(): MorphTo
    {
        return $this->morphTo();
    }
}
