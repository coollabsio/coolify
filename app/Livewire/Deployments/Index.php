<?php

namespace App\Livewire\Deployments;

use App\Enums\ApplicationDeploymentStatus;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Project;
use App\Models\Server;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public string $project = 'all';

    public string $server = 'all';

    public string $source = 'all';

    public string $status = 'all';

    public int $perPage = 25;

    public function updated(string $property): void
    {
        if (in_array($property, ['project', 'server', 'source', 'status', 'perPage'], true)) {
            $this->perPage = max(10, min(100, $this->perPage));
            $this->resetPage();
        }
    }

    public function render(): View
    {
        $serverIds = Server::ownedByCurrentTeamCached()->pluck('id');
        $base = ApplicationDeploymentQueue::query()->whereIn('server_id', $serverIds);

        $projects = Project::ownedByCurrentTeam()->orderBy('name')->get(['id', 'name']);
        $servers = Server::ownedByCurrentTeamCached()->sortBy('name')->values();
        $sources = (clone $base)->whereNotNull('git_type')->distinct()->orderBy('git_type')->pluck('git_type');
        $statuses = collect(ApplicationDeploymentStatus::cases())->pluck('value');

        $deployments = (clone $base)
            ->with(['application.environment.project'])
            ->when($this->project !== 'all', fn (Builder $q) => $q->whereHas('application.environment', fn (Builder $e) => $e->where('project_id', $this->project)))
            ->when($this->server !== 'all', fn (Builder $q) => $q->where('server_id', $this->server))
            ->when($this->source !== 'all', fn (Builder $q) => $q->where('git_type', $this->source))
            ->when($this->status !== 'all', fn (Builder $q) => $q->where('status', $this->status))
            ->orderByDesc('id')
            ->paginate($this->perPage);

        $options = fn ($items, string $all) => [
            ['value' => 'all', 'label' => $all],
            ...$items->all(),
        ];

        return view('livewire.deployments.index', [
            'deployments' => $deployments,
            'projectOptions' => $projects->count() > 1 ? $options($projects->map(fn ($p) => ['value' => (string) $p->id, 'label' => $p->name]), 'All projects') : null,
            'serverOptions' => $servers->count() > 1 ? $options($servers->map(fn ($s) => ['value' => (string) $s->id, 'label' => $s->name]), 'All servers') : null,
            'sourceOptions' => $sources->count() > 1 ? $options($sources->map(fn ($s) => ['value' => $s, 'label' => Str::headline($s)]), 'All sources') : null,
            'statusOptions' => $options($statuses->map(fn ($s) => ['value' => $s, 'label' => Str::headline($s)]), 'All statuses'),
        ]);
    }
}
