<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Connects one environment to the coolify.json file that manages it.
 */
class EnvironmentConfigLink extends BaseModel
{
    use HasFactory;

    public const MODE_GIT = 'git';

    public const MODE_API = 'api';

    protected $fillable = [
        'environment_id',
        'mode',
        'source_type',
        'source_id',
        'private_key_id',
        'repository',
        'branch',
        'file_path',
        'read_server_id',
        'webhook_secret',
        'auto_apply',
        'suspended_at',
        'suspended_by',
        'created_by',
        'pending_commit_sha',
        'pending_held_deployments',
        'pending_pushed_at',
        'last_applied_revision_id',
    ];

    protected $hidden = ['webhook_secret'];

    protected function casts(): array
    {
        return [
            'webhook_secret' => 'encrypted',
            'auto_apply' => 'boolean',
            'suspended_at' => 'datetime',
            'pending_held_deployments' => 'array',
            'pending_pushed_at' => 'datetime',
        ];
    }

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function privateKey(): BelongsTo
    {
        return $this->belongsTo(PrivateKey::class);
    }

    public function readServer(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'read_server_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(EnvironmentConfigRevision::class, 'link_id');
    }

    public function lastAppliedRevision(): BelongsTo
    {
        return $this->belongsTo(EnvironmentConfigRevision::class, 'last_applied_revision_id');
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    public function isGit(): bool
    {
        return $this->mode === self::MODE_GIT;
    }

    public function teamId(): int
    {
        return (int) $this->environment->project->team_id;
    }
}
