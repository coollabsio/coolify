<?php

namespace App\Models\V5;

use App\Models\Team;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContainerStatus extends V5Model
{
    protected $table = 'v5_container_statuses';

    protected bool $hasUuidColumn = false;

    protected $fillable = [
        'team_id',
        'server_id',
        'container_id',
        'container_name',
        'image',
        'status',
        'status_message',
        'status_observed_at',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'status_observed_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
