<?php

namespace App\Livewire;

use App\Models\ApplicationDeploymentQueue;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use App\Services\DashboardHealthcheckAlertService;
use App\Services\DashboardStatsService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

class Dashboard extends Component
{
    private const LATEST_DEPLOYMENTS_LIMIT = 5;

    public Collection $servers;

    public Collection $privateKeys;

    public array $stats = [];

    public Collection $downWithoutHealthcheck;

    public function mount(
        DashboardStatsService $dashboardStatsService,
        DashboardHealthcheckAlertService $dashboardHealthcheckAlertService,
    ): void {
        $this->privateKeys = PrivateKey::ownedByCurrentTeamCached();
        $this->servers = Server::ownedByCurrentTeamCached()->load('settings');
        $this->stats = $dashboardStatsService->forTeam($this->servers);
        $this->downWithoutHealthcheck = $dashboardHealthcheckAlertService->downWithoutHealthcheckForTeam();
    }

    public function getListeners(): array
    {
        $teamId = auth()->user()->currentTeam()->id;

        return [
            "echo-private:team.{$teamId},ServiceChecked" => 'refreshStats',
        ];
    }

    public function refreshStats(
        DashboardStatsService $dashboardStatsService,
        DashboardHealthcheckAlertService $dashboardHealthcheckAlertService,
    ): void {
        unset($this->latestDeployments, $this->hasActiveDeployments);

        $this->servers = Server::ownedByCurrentTeam()->with('settings')->get();
        $this->stats = $dashboardStatsService->forTeam($this->servers);
        $this->downWithoutHealthcheck = $dashboardHealthcheckAlertService->downWithoutHealthcheckForTeam();
    }

    #[Computed]
    public function latestDeployments(): Collection
    {
        if ($this->servers->isEmpty()) {
            return collect();
        }

        return ApplicationDeploymentQueue::query()
            ->with([
                'application:id,uuid,name,environment_id,destination_id,destination_type,git_repository,git_branch',
                'application.environment:id,uuid,project_id',
                'application.environment.project:id,uuid,name',
                'application.destination' => function ($morphTo) {
                    $morphTo->morphWith([
                        StandaloneDocker::class => ['server:id'],
                        SwarmDocker::class => ['server:id'],
                    ]);
                },
            ])
            ->whereIn('server_id', $this->servers->pluck('id'))
            ->orderByDesc('created_at')
            ->limit(self::LATEST_DEPLOYMENTS_LIMIT)
            ->get();
    }

    #[Computed]
    public function hasActiveDeployments(): bool
    {
        return $this->latestDeployments->contains(
            fn (ApplicationDeploymentQueue $deployment) => in_array($deployment->status, ['queued', 'in_progress'], true)
        );
    }

    public function render()
    {
        return view('livewire.dashboard');
    }
}
