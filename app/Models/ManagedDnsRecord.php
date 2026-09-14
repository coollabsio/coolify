<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ManagedDnsRecord extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'team_id', 'integration_token_id', 'dns_provider_zone_id', 'resource_type', 'resource_id',
        'provider_record_id', 'type', 'name', 'content',
    ];

    public function zone(): BelongsTo
    {
        return $this->belongsTo(DnsProviderZone::class, 'dns_provider_zone_id');
    }

    public function integrationToken(): BelongsTo
    {
        return $this->belongsTo(IntegrationToken::class);
    }

    public function resource(): MorphTo
    {
        return $this->morphTo();
    }
}
