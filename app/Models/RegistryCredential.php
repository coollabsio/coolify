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

    /** Whether this login is for the registry of the image, by Docker's rule for the registry host. */
    public function matchesImage(string $image): bool
    {
        return $this->registry === DockerRegistryLogins::registryFromImage($image);
    }

    /** Why the credential cannot be deleted yet, or null when it can. */
    public function deletionBlocker(): ?string
    {
        $count = $this->workloads()->count();

        return $count === 0 ? null : "This credential is used by {$count} ".str('application')->plural($count).'. Select another credential there first.';
    }
}
