<?php

namespace App\Models;

use App\Notifications\Channels\SendsEmail;
use App\Notifications\TrnsactionalEmails\ResetPassword;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements SendsEmail
{
    use HasApiTokens, HasFactory, Notifiable, TwoFactorAuthenticatable;

    protected $fillable = [
        'id',
        'name',
        'email',
        'password',
    ];
    protected $hidden = [
        'password',
        'remember_token',
    ];
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();
        static::created(function (User $user) {
            $team = [
                'name' => $user->name . "'s Team",
                'personal_team' => true,
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
        $this->notify(new ResetPassword($token));
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
        $role = $teams->where('id', session('currentTeam')->id)->first()->pivot->role;
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

    public function personalTeam()
    {
        return $this->teams()->where('personal_team', true)->first();
    }

    public function currentTeam()
    {
        return $this->teams()->where('team_id', session('currentTeam')->id)->first();
    }

    public function otherTeams()
    {
        $team_id = session('currentTeam')->id;
        return auth()->user()->teams->filter(function ($team) use ($team_id) {
            return $team->id != $team_id;
        });
    }

    public function role()
    {
        if ($this->teams()->where('team_id', 0)->first()) {
            return 'admin';
        }
        return $this->teams()->where('team_id', session('currentTeam')->id)->first()->pivot->role;
    }

    public function resources()
    {
        $team_id = session('currentTeam')->id;
        $data = Application::where('team_id', $team_id)->get();
        return $data;
    }
}
