<?php

namespace App\Models;

use App\Enums\ConfigRevisionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One plan of a coolify.json file for one environment, and what happened to it.
 */
class EnvironmentConfigRevision extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'link_id',
        'trigger',
        'commit_sha',
        'file_hash',
        'file_contents',
        'created_by',
        'state_hash',
        'format_version',
        'plan',
        'status',
        'error',
        'approved_by',
        'approved_at',
        'delete_data',
        'held_deployments',
        'started_at',
        'applied_at',
    ];

    protected $hidden = ['file_contents'];

    protected function casts(): array
    {
        return [
            'file_contents' => 'encrypted',
            'plan' => 'array',
            'delete_data' => 'array',
            'held_deployments' => 'array',
            'status' => ConfigRevisionStatus::class,
            'approved_at' => 'datetime',
            'started_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    public function link(): BelongsTo
    {
        return $this->belongsTo(EnvironmentConfigLink::class, 'link_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
