<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DnsProviderZone extends BaseModel
{
    use HasFactory;

    protected $fillable = ['integration_token_id', 'provider_zone_id', 'name', 'account_id', 'account_name'];

    public function integrationToken(): BelongsTo
    {
        return $this->belongsTo(IntegrationToken::class);
    }

    public function managedRecords(): HasMany
    {
        return $this->hasMany(ManagedDnsRecord::class);
    }
}
