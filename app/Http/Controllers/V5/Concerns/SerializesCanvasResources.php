<?php

namespace App\Http\Controllers\V5\Concerns;

use App\Models\Team;
use App\Models\V5\Application as V5Application;
use App\Models\V5\Server as V5Server;
use App\Support\V5\CanvasResourceSerializer;
use Illuminate\Database\Eloquent\Builder;

trait SerializesCanvasResources
{
    /**
     * @return array<string, mixed>
     */
    protected function serializeApplication(V5Application $application): array
    {
        return app(CanvasResourceSerializer::class)->serializeApplication($application);
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeCaddyIngress(V5Server $server, int $index = 0): array
    {
        return app(CanvasResourceSerializer::class)->serializeCaddyIngress($server, $index);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function caddyIngresses(mixed $currentTeam): array
    {
        if (! $currentTeam instanceof Team) {
            return [];
        }

        return V5Server::query()
            ->where('team_id', $currentTeam->id)
            ->orderBy('name')
            ->get()
            ->filter(fn (V5Server $server) => $server->isIngress())
            ->values()
            ->map(fn (V5Server $server, int $index) => $this->serializeCaddyIngress($server, $index))
            ->all();
    }

    /**
     * @param  array{uuid: string}  $selectedProject
     * @param  array{uuid: string}  $selectedEnvironment
     * @return Builder<V5Application>
     */
    protected function applicationQuery(Team $currentTeam, array $selectedProject, array $selectedEnvironment): Builder
    {
        return V5Application::query()
            ->where('team_id', $currentTeam->id)
            ->whereHas('project', fn (Builder $query) => $query
                ->where('team_id', $currentTeam->id)
                ->where('uuid', $selectedProject['uuid']))
            ->whereHas('environment', fn (Builder $query) => $query
                ->where('uuid', $selectedEnvironment['uuid']));
    }
}
