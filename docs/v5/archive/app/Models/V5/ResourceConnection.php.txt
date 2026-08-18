<?php

namespace App\Models\V5;

use App\Models\Environment;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ResourceConnection extends V5Model
{
    protected $table = 'v5_resource_connections';

    protected $fillable = [
        'uuid',
        'team_id',
        'project_id',
        'environment_id',
        'resource_one_type',
        'resource_one_id',
        'resource_two_type',
        'resource_two_id',
        'resource_pair_key',
        'created_by_user_id',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function resourceOne(): MorphTo
    {
        return $this->morphTo('resource_one');
    }

    public function resourceTwo(): MorphTo
    {
        return $this->morphTo('resource_two');
    }

    public function rules(): HasMany
    {
        return $this->hasMany(ResourceConnectionRule::class, 'connection_id');
    }
}
