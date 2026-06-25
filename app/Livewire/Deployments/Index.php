<?php

namespace App\Livewire\Deployments;

use App\Enums\ApplicationDeploymentStatus;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public const PER_PAGE = 20;

    public string $deployment_type = 'all';

    public string $status = 'all';

    protected $queryString = [
        'deployment_type' => ['except' => 'all'],
        'status' => ['except' => 'all'],
    ];

    public function mount(): void
    {
        $this->sanitizeFilters();
    }

    public function updatedDeploymentType(): void
    {
        $this->sanitizeFilters();
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->sanitizeFilters();
        $this->resetPage();
    }

    public function deploymentTypeOptions(): array
    {
        return [
            'all' => 'All',
            'production' => 'Production',
            'preview' => 'Preview PR',
        ];
    }

    public function statusOptions(): array
    {
        return [
            'all' => 'All statuses',
            ApplicationDeploymentStatus::QUEUED->value => 'Queued',
            ApplicationDeploymentStatus::IN_PROGRESS->value => 'In Progress',
            ApplicationDeploymentStatus::FINISHED->value => 'Finished',
            ApplicationDeploymentStatus::FAILED->value => 'Failed',
            ApplicationDeploymentStatus::CANCELLED_BY_USER->value => 'Cancelled',
        ];
    }

    public function deployments(): LengthAwarePaginator
    {
        $teamId = currentTeam()?->id;
        $applicationIds = Application::query()
            ->whereHas('environment.project', function ($query) use ($teamId) {
                $query->where('team_id', $teamId);
            })
            ->pluck('id')
            ->map(fn ($id) => (string) $id);

        return ApplicationDeploymentQueue::query()
            ->select([
                'id',
                'deployment_uuid',
                'application_id',
                'pull_request_id',
                'commit',
                'commit_message',
                'status',
                'is_webhook',
                'is_api',
                'created_at',
                'finished_at',
                'server_id',
                'application_name',
                'server_name',
                'deployment_url',
                'rollback',
            ])
            ->with(['application.environment.project'])
            ->whereIn('application_id', $applicationIds)
            ->when($this->deployment_type === 'production', function ($query) {
                $query->where(function ($query) {
                    $query->whereNull('pull_request_id')->orWhere('pull_request_id', 0);
                });
            })
            ->when($this->deployment_type === 'preview', function ($query) {
                $query->where('pull_request_id', '>', 0);
            })
            ->when($this->status !== 'all', function ($query) {
                $query->where('status', $this->status);
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    public function render()
    {
        $deployments = $this->deployments();
        $servers = Server::query()
            ->with('settings')
            ->whereIn('id', $deployments->getCollection()->pluck('server_id')->filter()->unique())
            ->get()
            ->keyBy('id');

        return view('livewire.deployments.index', [
            'deployments' => $deployments,
            'servers' => $servers,
        ]);
    }

    private function sanitizeFilters(): void
    {
        if (! array_key_exists($this->deployment_type, $this->deploymentTypeOptions())) {
            $this->deployment_type = 'all';
        }

        if (! array_key_exists($this->status, $this->statusOptions())) {
            $this->status = 'all';
        }
    }
}
