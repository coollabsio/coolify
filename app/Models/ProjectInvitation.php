<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectInvitation extends Model
{
    protected $fillable = [
        'project_id',
        'team_id',
        'uuid',
        'email',
        'role',
        'link',
        'via',
        'invited_by',
    ];

    /**
     * Set the email attribute to lowercase.
     */
    public function setEmailAttribute(string $value): void
    {
        $this->attributes['email'] = strtolower($value);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public static function ownedByCurrentTeam(): \Illuminate\Database\Eloquent\Builder
    {
        return static::where('team_id', currentTeam()->id);
    }

    public function isValid(): bool
    {
        $createdAt = $this->created_at;
        $diff = $createdAt->diffInDays(now());
        if ($diff <= config('constants.invitation.link.expiration_days')) {
            return true;
        }

        $this->delete();

        return false;
    }
}
