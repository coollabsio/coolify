<?php

namespace App\Livewire\Server;

use App\Jobs\DockerCleanupJob;
use App\Models\DockerCleanupExecution;
use App\Models\Server;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\Component;

class DockerCleanup extends Component
{
    use AuthorizesRequests;

    public Server $server;

    public array $parameters = [];

    #[Validate(['string', 'required'])]
    public string $dockerCleanupFrequency = '*/10 * * * *';

    #[Validate(['integer', 'min:1', 'max:99'])]
    public int $dockerCleanupThreshold = 10;

    #[Validate('boolean')]
    public bool $forceDockerCleanup = false;

    #[Validate('boolean')]
    public bool $deleteUnusedVolumes = false;

    #[Validate('boolean')]
    public bool $deleteUnusedNetworks = false;

    #[Validate('boolean')]
    public bool $disableApplicationImageRetention = false;

    /**
     * The scheduled cleanup is stale when its next run is more than 10 minutes overdue,
     * meaning the scheduled job dispatcher did not run it.
     */
    #[Computed]
    public function isCleanupStale(): bool
    {
        // The dispatcher does not run cleanups on servers without a real IP address.
        if (blank($this->server->ip) || $this->server->hasPlaceholderIp()) {
            return false;
        }

        $nextRunAt = $this->server->settings->docker_cleanup_next_run_at;

        return $nextRunAt !== null && $nextRunAt->lt(now()->subMinutes(10));
    }

    #[Computed]
    public function lastExecutionTime(): ?string
    {
        return DockerCleanupExecution::where('server_id', $this->server->id)
            ->orderBy('created_at', 'desc')
            ->first()
            ?->created_at
            ?->diffForHumans();
    }

    #[Computed]
    public function isSchedulerHealthy(): bool
    {
        return Cache::get('scheduled-job-manager:heartbeat') !== null;
    }

    public function mount(string $server_uuid)
    {
        try {
            $this->server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
            $this->parameters = get_route_parameters();
            $this->syncData();
        } catch (\Throwable) {
            return redirect()->route('server.index');
        }
    }

    private function syncData(bool $toModel = false): void
    {
        if ($toModel) {
            $this->validate();
            $this->server->settings->force_docker_cleanup = $this->forceDockerCleanup;
            $this->server->settings->docker_cleanup_frequency = $this->dockerCleanupFrequency;
            $this->server->settings->docker_cleanup_threshold = $this->dockerCleanupThreshold;
            $this->server->settings->delete_unused_volumes = $this->deleteUnusedVolumes;
            $this->server->settings->delete_unused_networks = $this->deleteUnusedNetworks;
            $this->server->settings->disable_application_image_retention = $this->disableApplicationImageRetention;
            $this->server->settings->save();
        } else {
            $this->forceDockerCleanup = $this->server->settings->force_docker_cleanup;
            $this->dockerCleanupFrequency = $this->server->settings->docker_cleanup_frequency;
            $this->dockerCleanupThreshold = $this->server->settings->docker_cleanup_threshold;
            $this->deleteUnusedVolumes = $this->server->settings->delete_unused_volumes;
            $this->deleteUnusedNetworks = $this->server->settings->delete_unused_networks;
            $this->disableApplicationImageRetention = $this->server->settings->disable_application_image_retention;
        }
    }

    public function instantSave()
    {
        try {
            $this->authorize('update', $this->server);
            $this->syncData(true);
            $this->dispatch('success', 'Server updated.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function manualCleanup()
    {
        try {
            $this->authorize('update', $this->server);
            DockerCleanupJob::dispatch($this->server, true, $this->deleteUnusedVolumes, $this->deleteUnusedNetworks);
            auditLog('ui.server.docker_cleanup_started', [
                'team_id' => $this->server->team_id,
                'server_uuid' => $this->server->uuid,
                'server_name' => $this->server->name,
                'delete_unused_volumes' => $this->deleteUnusedVolumes,
                'delete_unused_networks' => $this->deleteUnusedNetworks,
            ]);
            $this->dispatch('success', 'Manual cleanup job started. Depending on the amount of data, this might take a while.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function submit()
    {
        try {
            if (! validate_cron_expression($this->dockerCleanupFrequency)) {
                $this->dockerCleanupFrequency = $this->server->settings->getOriginal('docker_cleanup_frequency');
                throw new \Exception('Invalid Cron / Human expression for Docker Cleanup Frequency.');
            }
            $this->authorize('update', $this->server);
            $this->syncData(true);
            $this->dispatch('success', 'Server updated.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.server.docker-cleanup');
    }
}
