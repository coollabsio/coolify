<?php

namespace App\Models;

use App\Enums\NodeRole;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class Node extends BaseModel
{
    use Auditable, HasFactory;

    protected $guarded = [];

    protected $hidden = ['sentinel_token'];

    protected function casts(): array
    {
        return [
            'role' => NodeRole::class,
            'sentinel_token' => 'encrypted',
            'metadata' => 'array',
            'is_reachable' => 'boolean',
            'is_usable' => 'boolean',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function privateKey(): BelongsTo
    {
        return $this->belongsTo(PrivateKey::class);
    }

    public function workloads(): BelongsToMany
    {
        return $this->belongsToMany(NodeWorkload::class, 'node_workload_nodes')->withTimestamps();
    }

    public function containers(): HasMany
    {
        return $this->hasMany(NodeContainer::class);
    }

    public function operations(): HasMany
    {
        return $this->hasMany(NodeOperation::class);
    }

    public function isNonRoot(): bool
    {
        return $this->user !== 'root';
    }

    public function isIpv6(): bool
    {
        return filter_var($this->ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
    }

    public function ensureValidSentinelToken(): string
    {
        $token = $this->sentinel_token;
        if (! is_string($token) || ! preg_match('/\A[a-zA-Z0-9._\-+=\/]+\z/', $token)) {
            $token = encrypt(json_encode(['node_uuid' => $this->uuid]));
            $this->forceFill(['sentinel_token' => $token])->saveQuietly();
        }

        return $token;
    }

    public function ensureSentinelUrl(): string
    {
        if (blank($this->sentinel_url)) {
            throw new RuntimeException('Set a reachable Coolify URL before enabling Sentinel.');
        }

        return $this->sentinel_url;
    }

    public function cacheKey(): string
    {
        return "flux:connection:{$this->uuid}";
    }

    public function hasRecentFluxHeartbeat(): bool
    {
        $connection = Cache::get($this->cacheKey());
        $heartbeat = data_get($connection, 'last_heartbeat_at');
        if (data_get($connection, 'status') !== 'connected' || ! is_string($heartbeat)) {
            return false;
        }

        try {
            return Carbon::parse($heartbeat)->isAfter(now()->subMinutes(2));
        } catch (\Throwable) {
            return false;
        }
    }

    public function restartSentinel(): ?string
    {
        return instant_remote_process(['systemctl restart sentinel.service'], $this);
    }
}
