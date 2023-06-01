<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Visus\Cuid2\Cuid2;
use Laravel\Fortify\TwoFactorAuthenticatable;

class User extends Authenticatable
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

        static::creating(function (Model $model) {
            $model->uuid = (string) new Cuid2(7);
        });
    }
    public function isPartOfRootTeam()
    {
        $found_root_team = auth()->user()->teams->filter(function ($team) {
            if ($team->id == 0) {
                return true;
            }
            return false;
        });
        return $found_root_team->count() > 0;
    }
    public function teams()
    {
        return $this->belongsToMany(Team::class);
    }

    public function currentTeam()
    {
        return $this->belongsTo(Team::class);
    }

    public function otherTeams()
    {
        $team_id = session('currentTeam')->id;
        return auth()->user()->teams->filter(function ($team) use ($team_id) {
            return $team->id != $team_id;
        });
    }
    public function resources()
    {
        $team_id = session('currentTeam')->id;
        $data = Application::where('team_id', $team_id)->get();
        return $data;
    }
}
