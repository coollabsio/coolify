<?php

namespace App\Jobs;

use App\Enums\V5\ApplicationStatus;
use App\Enums\V5\ContainerState;
use App\Enums\V5\ServerStatus;
use App\Models\V5\Application as V5Application;
use App\Models\V5\ContainerStatus;
use App\Models\V5\Server as V5Server;
use App\Services\Flux\FluxClient;
use App\Support\V5\StatusObservation;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Actively reconciles one v5 server against the containers coold actually
 * reports. V5 status is normally push-only (coold -> flux -> webhook), so a
 * dropped webhook leaves rows stale forever; this job is the pull-based
 * safety net scheduled via V5ReconcileServersJob.
 */
class V5ReconcileServerStateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Reconcile runs on its own queue so the 5-minute fleet fan-out (one
     * blocking flux call per server) can never starve user-triggered deploys
     * and bootstraps sharing the default queue. Set via onQueue() in the
     * constructor rather than a `$queue` property redeclaration, which the
     * Queueable trait already defines (redeclaring with a default is an
     * incompatible property composition and fatals on PHP 8.5).
     */
    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $serverId)
    {
        $this->onQueue('v5-reconcile');
    }

    public function handle(FluxClient $fluxClient): void
    {
        $server = V5Server::query()->find($this->serverId);

        if (! $server instanceof V5Server) {
            return;
        }

        $hostId = $server->fluxHostId();

        if ($hostId === '') {
            Log::warning('V5 reconcile skipped: server is missing a Flux host id.', ['server_id' => $server->id]);

            return;
        }

        // The moment we query coold is the observation time for every row this
        // pass writes; a webhook that lands with a newer observation while this
        // (possibly delayed) snapshot is processed must win the watermark.
        $observedAt = CarbonImmutable::now();

        try {
            $containers = collect($fluxClient->listContainers($hostId));
        } catch (\Throwable $exception) {
            $this->markServerUnreachable($server, $exception, $observedAt);

            return;
        }

        $this->markServerReachable($server, $containers->count(), $observedAt);
        $this->refreshContainerStatuses($server, $containers, $observedAt);
        $this->reconcileApplications($server, $containers, $observedAt);
    }

    private function markServerUnreachable(V5Server $server, \Throwable $exception, CarbonInterface $observedAt): void
    {
        Log::warning('V5 reconcile could not reach the server via flux.', [
            'server_id' => $server->id,
            'error' => $exception->getMessage(),
        ]);

        $attributes = [
            'last_status_check' => 'reconcile',
            'last_status_output' => str($exception->getMessage())->limit(1000)->toString(),
            'last_status_checked_at' => now(),
        ];

        if (! StatusObservation::isStale($observedAt, $server->status_observed_at, 'server status', ['server_id' => $server->id])) {
            // Only an installed server can degrade to unreachable; added or
            // failed servers keep their bootstrap-driven status.
            $attributes['status'] = $server->status === ServerStatus::Installed->value
                ? ServerStatus::Unreachable->value
                : $server->status;
            $attributes['status_observed_at'] = $observedAt;
        }

        $server->update($attributes);
    }

    private function markServerReachable(V5Server $server, int $containerCount, CarbonInterface $observedAt): void
    {
        $attributes = [
            'last_status_check' => 'reconcile',
            'last_status_output' => "Reconciled {$containerCount} containers from coold.",
            'last_status_checked_at' => now(),
        ];

        if (! StatusObservation::isStale($observedAt, $server->status_observed_at, 'server status', ['server_id' => $server->id])) {
            $attributes['status'] = $server->status === ServerStatus::Unreachable->value
                ? ServerStatus::Installed->value
                : $server->status;
            $attributes['status_observed_at'] = $observedAt;
        }

        $server->update($attributes);
    }

    /**
     * @param  Collection<int, mixed>  $containers
     */
    private function refreshContainerStatuses(V5Server $server, Collection $containers, CarbonInterface $observedAt): void
    {
        $containers->each(function (mixed $container) use ($server, $observedAt): void {
            if (! is_array($container) || ! is_string($container['id'] ?? null) || $container['id'] === '') {
                return;
            }

            $existing = ContainerStatus::query()
                ->where('server_id', $server->id)
                ->where('container_id', $container['id'])
                ->first();

            if (StatusObservation::isStale($observedAt, $existing?->status_observed_at, 'container status', [
                'server_id' => $server->id,
                'container_id' => $container['id'],
            ])) {
                return;
            }

            ContainerStatus::query()->updateOrCreate([
                'server_id' => $server->id,
                'container_id' => $container['id'],
            ], [
                'team_id' => $server->team_id,
                'container_name' => is_string($container['name'] ?? null) ? $container['name'] : null,
                'image' => is_string($container['image'] ?? null) ? $container['image'] : null,
                'status' => $this->containerState($container, ContainerState::class),
                'status_message' => 'Container state reconciled from coold.',
                'status_observed_at' => $observedAt,
                'last_seen_at' => now(),
            ]);
        });
    }

    /**
     * @param  Collection<int, mixed>  $containers
     */
    private function reconcileApplications(V5Server $server, Collection $containers, CarbonInterface $observedAt): void
    {
        V5Application::query()
            ->where('server_id', $server->id)
            ->get()
            ->each(function (V5Application $application) use ($containers, $observedAt): void {
                try {
                    $this->reconcileApplication($application, $containers, $observedAt);
                } catch (\Throwable $exception) {
                    Log::warning('V5 reconcile failed for an application.', [
                        'application_id' => $application->id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            });
    }

    /**
     * @param  Collection<int, mixed>  $containers
     */
    private function reconcileApplication(V5Application $application, Collection $containers, CarbonInterface $observedAt): void
    {
        $container = $containers->first(function (mixed $container) use ($application): bool {
            return is_array($container)
                && (($application->runtime_container_id !== null && ($container['id'] ?? null) === $application->runtime_container_id)
                    || ($container['name'] ?? null) === $application->container_name);
        });

        if (! is_array($container)) {
            // A creating application without a container id simply has not
            // materialized yet; the deploy job will settle it.
            if ($application->status === ApplicationStatus::Creating->value && $application->runtime_container_id === null) {
                return;
            }

            if (StatusObservation::isStale($observedAt, $application->status_observed_at, 'application status', ['application_id' => $application->id])) {
                return;
            }

            $attributes = [
                'status' => ApplicationStatus::Exited->value,
                'status_observed_at' => $observedAt,
            ];

            if ($application->status !== ApplicationStatus::Exited->value) {
                $attributes['status_message'] = 'Container not found on server during reconcile.';
            }

            $application->update($attributes);

            return;
        }

        if (StatusObservation::isStale($observedAt, $application->status_observed_at, 'application status', ['application_id' => $application->id])) {
            return;
        }

        $status = $this->containerState($container, ApplicationStatus::class);

        $attributes = [
            'status' => $status,
            'status_observed_at' => $observedAt,
            'runtime_container_id' => is_string($container['id'] ?? null) && $container['id'] !== ''
                ? $container['id']
                : $application->runtime_container_id,
        ];

        // Only write status_message when the status actually changes: the
        // status column is what a viewer cares about, and a constant message
        // would otherwise fire a broadcast + full re-serialization every cycle.
        if ($status !== $application->status) {
            $attributes['status_message'] = 'Container state reconciled from coold.';
        }

        $application->update($attributes);
    }

    /**
     * @param  array<string, mixed>  $container
     * @param  class-string<ApplicationStatus|ContainerState>  $enumClass
     */
    private function containerState(array $container, string $enumClass): string
    {
        $state = $container['state'] ?? null;
        $raw = is_string($state) && $state !== '' ? $state : null;

        return StatusObservation::normalize($raw, $enumClass) ?? $enumClass::Unknown->value;
    }
}
