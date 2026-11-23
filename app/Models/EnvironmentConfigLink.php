<?php

namespace App\Models;

use App\Services\ProjectConfig\ManagedResources;
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

    protected static function booted(): void
    {
        static::saved(fn () => ManagedResources::forgetLinks());
        static::deleted(fn () => ManagedResources::forgetLinks());
    }

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
        'destination_type',
        'destination_id',
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

    /**
     * The default destination, on the server of the link. Needed only when that server has more than one destination.
     */
    public function destination(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Where a resource goes when the file names no server: the server of the link, and the destination of the link
     * when that server has more than one destination.
     *
     * @return array{server?: string, destination?: string}
     */
    public function defaultPlacement(): array
    {
        $server = $this->readServer;
        if ($server === null) {
            return [];
        }
        $placement = ['server' => $server->name];
        $destination = $this->destination;
        if ($destination !== null && (int) $destination->server_id === (int) $server->id && $server->destinations()->count() > 1) {
            $placement['destination'] = $destination->network;
        }

        return $placement;
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
