<?php

namespace App\Models;

use App\Traits\HasSafeStringAttribute;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;

class KubernetesCluster extends BaseModel
{
    use HasFactory;
    use HasSafeStringAttribute;

    protected $fillable = [
        'server_id',
        'name',
        'namespace',
        'context',
        'kubeconfig_path',
        'kubeconfig',
        'ingress_class',
        'service_type',
        'replicas',
        'autoscaling_enabled',
        'min_replicas',
        'max_replicas',
        'target_cpu_utilization_percentage',
    ];

    protected function casts(): array
    {
        return [
            'kubeconfig' => 'encrypted',
            'replicas' => 'integer',
            'autoscaling_enabled' => 'boolean',
            'min_replicas' => 'integer',
            'max_replicas' => 'integer',
            'target_cpu_utilization_percentage' => 'integer',
        ];
    }

    public function applications(): MorphMany
    {
        return $this->morphMany(Application::class, 'destination');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public static function ownedByCurrentTeam(): Builder
    {
        return static::whereHas('server', fn ($q) => $q->whereTeamId(currentTeam()->id));
    }

    public static function ownedByCurrentTeamAPI(int $teamId): Builder
    {
        return static::whereHas('server', fn ($q) => $q->whereTeamId($teamId));
    }

    /**
     * Get the server attribute using identity map caching.
     */
    public function getServerAttribute(): ?Server
    {
        if ($this->relationLoaded('server')) {
            return $this->getRelation('server');
        }

        $server = Server::findCached($this->server_id);

        if ($server) {
            $this->setRelation('server', $server);
        }

        return $server;
    }

    public function services(): Collection
    {
        return collect();
    }

    public function databases(): Collection
    {
        return collect();
    }

    public function attachedTo(): bool
    {
        return $this->applications?->count() > 0;
    }

    public function configurationDirectory(): string
    {
        return base_configuration_dir()."/kubernetes/{$this->uuid}";
    }

    public function storedKubeconfigPath(): string
    {
        return $this->configurationDirectory().'/kubeconfig';
    }

    public function effectiveKubeconfigPath(): ?string
    {
        if (filled($this->kubeconfig)) {
            return $this->storedKubeconfigPath();
        }

        return $this->kubeconfig_path;
    }
}
