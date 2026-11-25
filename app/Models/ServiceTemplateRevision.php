<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The compose of a service before a template change was applied or restored, so the user can revert it.
 */
class ServiceTemplateRevision extends Model
{
    public const KEEP = 5;

    protected $fillable = [
        'docker_compose_raw',
        'template_reference_hash',
    ];

    protected $hidden = [
        'docker_compose_raw',
    ];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
