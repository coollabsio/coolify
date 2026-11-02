<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of build output, appended as Coolify reads it from Sentinel. */
class NodeBuildLogLine extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'logged_at' => 'datetime',
        ];
    }

    public function build(): BelongsTo
    {
        return $this->belongsTo(NodeBuild::class, 'node_build_id');
    }
}
