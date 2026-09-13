<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NodeCluster extends BaseModel
{
    use Auditable, HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'desired_revision' => 'integer',
            'wireguard_port' => 'integer',
            'last_reconciled_at' => 'datetime',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function nodes(): HasMany
    {
        return $this->hasMany(Node::class);
    }

    public function hasActivatedNetwork(): bool
    {
        return $this->network_status === 'active' || $this->last_reconciled_at !== null;
    }
}
