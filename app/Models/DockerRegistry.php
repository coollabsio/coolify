<?php

namespace App\Models;

use App\Traits\HasSafeStringAttribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class DockerRegistry extends BaseModel
{
    use HasFactory, HasSafeStringAttribute;

    protected $fillable = [
        'name',
        'description',
        'registry_url',
        'username',
        'password',
        'team_id',
    ];

    protected $casts = [
        'password' => 'encrypted',
    ];

    protected static function booted()
    {
        static::saving(function (DockerRegistry $registry) {
            if ($registry->registry_url !== null) {
                $registry->registry_url = trim($registry->registry_url);
                if ($registry->registry_url === '') {
                    $registry->registry_url = null;
                }
            }

            if ($registry->username !== null) {
                $registry->username = trim($registry->username);
                if ($registry->username === '') {
                    $registry->username = null;
                }
            }
        });
    }

    public static function ownedByCurrentTeam(array $select = ['*'])
    {
        $selectArray = collect($select)->concat(['id']);

        return self::whereTeamId(currentTeam()->id)->select($selectArray->all())->orderBy('name');
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function applications()
    {
        return $this->hasMany(Application::class);
    }

    public function isInUse(): bool
    {
        return $this->applications()->exists();
    }

    public function safeDelete()
    {
        if (! $this->isInUse()) {
            $this->delete();

            return true;
        }

        return false;
    }
}
