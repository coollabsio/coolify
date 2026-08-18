<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DockerCleanupExecution extends BaseModel
{
    protected $fillable = [
        'server_id',
        'status',
        'message',
        'cleanup_log',
        'finished_at',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
