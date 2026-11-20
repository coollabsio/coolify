<?php

namespace App\Models;

use App\Traits\Auditable;
use Database\Factories\OriginAppFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * A Cursor Origin App. Coolify signs app JWTs with the Ed25519 private key and
 * exchanges them for short-lived installation tokens (see bootstrap/helpers/origin.php).
 */
class OriginApp extends BaseModel
{
    /** @use HasFactory<OriginAppFactory> */
    use Auditable, HasFactory;

    protected $fillable = [
        'name',
        'api_url',
        'html_url',
        'app_id',
        'installation_id',
        'namespace_slug',
        'public_key',
        'private_key',
        'is_system_wide',
        'team_id',
    ];

    protected $hidden = [
        'private_key',
    ];

    protected function casts(): array
    {
        return [
            'private_key' => 'encrypted',
            'is_system_wide' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (OriginApp $originApp) {
            if ($originApp->applications()->count() > 0) {
                throw new \RuntimeException('This source is being used by an application. Please delete all applications first.');
            }
        });
    }

    public static function ownedByCurrentTeam()
    {
        return OriginApp::where(function ($query) {
            $query->where('team_id', currentTeam()->id)
                ->orWhere('is_system_wide', true);
        });
    }

    public static function connected()
    {
        return OriginApp::ownedByCurrentTeam()->whereNotNull('app_id')->whereNotNull('installation_id');
    }

    public function applications()
    {
        return $this->morphMany(Application::class, 'source');
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function isConnected(): bool
    {
        return filled($this->app_id) && filled($this->installation_id) && filled($this->private_key);
    }

    /**
     * Origin is a hosted forge, so sources are always private.
     */
    public function getIsPublicAttribute(): bool
    {
        return false;
    }
}
