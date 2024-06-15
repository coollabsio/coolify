<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $uuid
 * @property int $team_id
 * @property string $email
 * @property string $role
 * @property string $link
 * @property string $via
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Team $team
 *
 * @method static \Illuminate\Database\Eloquent\Builder|TeamInvitation newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|TeamInvitation newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|TeamInvitation query()
 * @method static \Illuminate\Database\Eloquent\Builder|TeamInvitation whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|TeamInvitation whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder|TeamInvitation whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|TeamInvitation whereLink($value)
 * @method static \Illuminate\Database\Eloquent\Builder|TeamInvitation whereRole($value)
 * @method static \Illuminate\Database\Eloquent\Builder|TeamInvitation whereTeamId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|TeamInvitation whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|TeamInvitation whereUuid($value)
 * @method static \Illuminate\Database\Eloquent\Builder|TeamInvitation whereVia($value)
 *
 * @mixin \Eloquent
 */
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

    public function isValid()
    {
        $createdAt = $this->created_at;
        $diff = $createdAt->diffInMinutes(now());
        if ($diff <= config('constants.invitation.link.expiration')) {
            return true;
        } else {
            $this->delete();

            return false;
        }
    }
}
