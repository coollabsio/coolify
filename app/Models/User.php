<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Visus\Cuid2\Cuid2;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function (Model $model) {
            $model->uuid = (string) new Cuid2();
        });
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
}
