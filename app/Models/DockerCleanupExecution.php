<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DockerCleanupExecution extends BaseModel
{
    protected $guarded = [];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
