<?php

namespace App\Models\V5;

use App\Models\PrivateKey;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Server extends V5Model
{
    protected $table = 'v5_servers';

    protected $fillable = [
        'team_id',
        'cluster_id',
        'created_by_user_id',
        'private_key_id',
        'name',
        'host',
        'ssh_user',
        'ssh_port',
        'status',
        'capabilities',
        'builder_enabled',
        'builder_capacity',
        'last_bootstrapped_at',
    ];

    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'builder_enabled' => 'boolean',
            'last_bootstrapped_at' => 'datetime',
        ];
    }

    public function cluster(): BelongsTo
    {
        return $this->belongsTo(Cluster::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function privateKey(): BelongsTo
    {
        return $this->belongsTo(PrivateKey::class);
    }
}
