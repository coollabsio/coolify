<?php

namespace App\Models\V5;

use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cluster extends V5Model
{
    protected $table = 'v5_clusters';

    protected $fillable = [
        'team_id',
        'created_by_user_id',
        'name',
        'description',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }
}
