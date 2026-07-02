<?php

namespace App\Models\V5;

use App\Events\V5CanvasResourceUpdated;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Application extends V5Model
{
    protected $table = 'v5_applications';

    protected $fillable = [
        'uuid',
        'team_id',
        'project_id',
        'environment_id',
        'server_id',
        'created_by_user_id',
        'name',
        'image',
        'container_name',
        'status',
        'status_message',
        'runtime_container_id',
        'mesh_namespace',
        'ingress_enabled',
        'internal_port',
        'canvas_x',
        'canvas_y',
    ];

    protected $attributes = [
        'status' => 'creating',
        'mesh_namespace' => 'default',
        'ingress_enabled' => false,
        'canvas_x' => 0,
        'canvas_y' => 0,
    ];

    protected static function booted(): void
    {
        static::updated(function (self $application): void {
            if ($application->wasChanged(['status', 'status_message', 'runtime_container_id'])) {
                V5CanvasResourceUpdated::dispatch($application->team_id, $application->id);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'ingress_enabled' => 'boolean',
            'internal_port' => 'integer',
            'canvas_x' => 'integer',
            'canvas_y' => 'integer',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
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

    public function domains(): HasMany
    {
        return $this->hasMany(ApplicationDomain::class);
    }
}
