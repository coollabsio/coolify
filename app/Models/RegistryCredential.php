<?php

namespace App\Models;

use App\Services\DockerRegistryLogins;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A team's login for one container registry (decision 0006). A cluster image application pulls
 * with it, and a Git application pushes its builds with it. Only the request to Flux carries the
 * password; it is never stored anywhere else.
 */
class RegistryCredential extends BaseModel
{
    protected $fillable = [
        'team_id',
        'name',
        'registry',
        'username',
        'password',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
        ];
    }

    public static function ownedByCurrentTeam()
    {
        return self::query()->where('team_id', currentTeam()->id);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function workloads(): HasMany
    {
        return $this->hasMany(NodeWorkload::class);
    }

    /** Clusters that store their builds in the registry of this login. */
    public function clusters(): HasMany
    {
        return $this->hasMany(NodeCluster::class, 'image_registry_credential_id');
    }

    /** Whether this login is for the registry of the image, by Docker's rule for the registry host. */
    public function matchesImage(string $image): bool
    {
        return $this->registry === DockerRegistryLogins::registryFromImage($image);
    }

    /** Why the credential cannot be deleted yet, or null when it can. */
    public function deletionBlocker(): ?string
    {
        $count = $this->workloads()->count();
        if ($count > 0) {
            return "This credential is used by {$count} ".str('application')->plural($count).'. Select another credential there first.';
        }
        $clusters = $this->clusters()->count();

        return $clusters === 0 ? null : "This credential is the image storage login of {$clusters} ".str('cluster')->plural($clusters).'. Select another credential there first.';
    }
}
