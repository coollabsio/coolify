<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ManagedDnsRecord extends BaseModel
{
    use HasFactory;

    public const OWNERSHIP_COMMENT_PREFIX = 'managed-by: coolify';

    protected $fillable = [
        'uuid', 'team_id', 'integration_token_id', 'dns_provider_zone_id',
        'provider_record_id', 'type', 'name', 'content', 'owned',
    ];

    protected function casts(): array
    {
        return [
            'owned' => 'boolean',
        ];
    }

    /**
     * The provider comment that proves Coolify created this record. Records without it are never modified or deleted automatically.
     */
    public static function ownershipCommentFor(string $uuid): string
    {
        $instanceId = substr((string) (config('app.id') ?: 'default'), 0, 40);

        return self::OWNERSHIP_COMMENT_PREFIX." {$instanceId}/{$uuid}";
    }

    public function ownershipComment(): string
    {
        return self::ownershipCommentFor($this->uuid);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(DnsProviderZone::class, 'dns_provider_zone_id');
    }

    public function integrationToken(): BelongsTo
    {
        return $this->belongsTo(IntegrationToken::class);
    }

    public function references(): HasMany
    {
        return $this->hasMany(ManagedDnsRecordReference::class);
    }

    public function addReference(Model $resource): ManagedDnsRecordReference
    {
        return $this->references()->firstOrCreate([
            'resource_type' => $resource->getMorphClass(),
            'resource_id' => $resource->getKey(),
        ]);
    }
}
