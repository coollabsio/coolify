<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeamInvitation extends Model
{
    protected $fillable = [
        'team_id',
        'uuid',
        'email',
        'role',
        'link',
        'via',
    ];

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public static function ownedByCurrentTeam()
    {
        return TeamInvitation::whereTeamId(currentTeam()->id);
    }

    public function isValid()
    {
        $createdAt = $this->created_at;
        $diff = $createdAt->diffInDays(now());
        if ($diff <= config('constants.invitation.link.expiration_days')) {
            return true;
        } else {
            $this->delete();

            return false;
        }
    }
}
