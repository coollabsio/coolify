<?php

namespace App\Models\V5;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ResourceConnectionRule extends V5Model
{
    protected $table = 'v5_resource_connection_rules';

    protected bool $hasUuidColumn = false;

    protected $fillable = [
        'connection_id',
        'source_resource_type',
        'source_resource_id',
        'target_resource_type',
        'target_resource_id',
        'protocol',
        'port',
    ];

    protected $attributes = [
        'protocol' => 'tcp',
    ];

    protected function casts(): array
    {
        return [
            'port' => 'integer',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(ResourceConnection::class, 'connection_id');
    }

    public function sourceResource(): MorphTo
    {
        return $this->morphTo('source_resource');
    }

    public function targetResource(): MorphTo
    {
        return $this->morphTo('target_resource');
    }
}
