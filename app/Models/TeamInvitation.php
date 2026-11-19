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
        'access',
    ];

    protected $casts = [
        'link' => 'encrypted',
        'access' => 'array',
    ];

    /**
     * Set the email attribute to lowercase.
     */
    public function setEmailAttribute(string $value): void
    {
        $this->attributes['email'] = strtolower($value);
    }

    /**
     * Pivot attributes for the new `team_user` row. Grants apply only to the member role.
     *
     * @return array{role: string, access: string|null}
     */
    public function membershipAttributes(): array
    {
        return [
            'role' => $this->role,
            'access' => $this->role === 'member' && $this->access !== null ? json_encode($this->access) : null,
        ];
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public static function ownedByCurrentTeam()
    {
        return TeamInvitation::whereTeamId(currentTeam()->id);
    }

    public function isValid(): bool
    {
        if (! $this->hasExpired()) {
            return true;
        } else {
            $this->delete();
            $user = User::whereEmail($this->email)->first();
            if (filled($user)) {
                $user->deleteIfNotVerifiedAndForcePasswordReset();
            }

            return false;
        }
    }

    public function hasExpired(): bool
    {
        return $this->created_at->diffInDays(now()) > config('constants.invitation.link.expiration_days');
    }
}
