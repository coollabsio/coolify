<?php

namespace App\Models;

use App\Notifications\Channels\SendsEmail;
use App\Notifications\TransactionalEmails\ResetPassword as TransactionalEmailsResetPassword;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements SendsEmail
{
    use HasApiTokens, HasFactory, Notifiable, TwoFactorAuthenticatable;

    protected $guarded = [];
    protected $hidden = [
        'password',
        'remember_token',
    ];
    protected $casts = [
        'email_verified_at' => 'datetime',
        'force_password_reset' => 'boolean',
        'show_boarding' => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();
        static::created(function (User $user) {
            $team = [
                'name' => $user->name . "'s Team",
                'personal_team' => true,
                'boarding' => true
            ];
            if ($user->id === 0) {
                $team['id'] = 0;
                $team['name'] = 'Root Team';
            }
            $new_team = Team::create($team);
            $user->teams()->attach($new_team, ['role' => 'owner']);
        });
    }

    public function teams()
    {
        return $this->belongsToMany(Team::class)->withPivot('role');
    }

    public function getRecepients($notification)
    {
        return $this->email;
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new TransactionalEmailsResetPassword($token));
    }

    public function isAdmin()
    {
        return $this->pivot->role === 'admin' || $this->pivot->role === 'owner';
    }

    public function isAdminFromSession()
    {
        if (auth()->user()->id === 0) {
            return true;
        }
        $teams = $this->teams()->get();

        $is_part_of_root_team = $teams->where('id', 0)->first();
        $is_admin_of_root_team = $is_part_of_root_team &&
            ($is_part_of_root_team->pivot->role === 'admin' || $is_part_of_root_team->pivot->role === 'owner');

        if ($is_part_of_root_team && $is_admin_of_root_team) {
            return true;
        }
        $role = $teams->where('id', auth()->user()->id)->first()->pivot->role;
        return $role === 'admin' || $role === 'owner';
    }

    public function isInstanceAdmin()
    {
        $found_root_team = auth()->user()->teams->filter(function ($team) {
            if ($team->id == 0) {
                return true;
            }
            return false;
        });
        return $found_root_team->count() > 0;
    }

    public function currentTeam()
    {
        return session('currentTeam');
    }

    public function otherTeams()
    {
        return auth()->user()->teams->filter(function ($team) {
            return $team->id != currentTeam()->id;
        });
    }

    public function role()
    {
        return session('currentTeam')->pivot->role;
    }
}
