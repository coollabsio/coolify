<?php

namespace App\Livewire\Deployments;

use App\Enums\ApplicationDeploymentStatus;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Project;
use App\Models\Server;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public string $search = '';

    public string $project = 'all';

    public string $server = 'all';

    public string $source = 'all';

    public string $status = 'all';

    public int $perPage = 10;

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'project', 'server', 'source', 'status', 'perPage'], true)) {
            $this->perPage = max(10, min(100, $this->perPage));
            $this->resetPage();
        }
    }

    public function render(): View
    {
        // Scoped by application, like the dashboard: a transferred server keeps the old team's deployment rows.
        $base = ApplicationDeploymentQueue::query()->ownedByTeam(currentTeam()->id)
            ->where('status', '!=', ApplicationDeploymentStatus::SKIPPED->value);

        $projects = Project::ownedByCurrentTeam()->orderBy('name')->get(['id', 'name']);
        $servers = Server::ownedByCurrentTeamCached()->sortBy('name')->values();
        $sources = (clone $base)->whereNotNull('git_type')->distinct()->orderBy('git_type')->pluck('git_type');
        $statuses = collect(ApplicationDeploymentStatus::cases())
            ->reject(fn (ApplicationDeploymentStatus $status): bool => $status === ApplicationDeploymentStatus::SKIPPED)
            ->pluck('value');

        $search = trim($this->search);

        $deployments = (clone $base)
            ->with([
                'application' => fn ($query) => $query->withoutGlobalScope('withRelations')->select(['id', 'environment_id']),
                'application.environment.project',
            ])
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereLike('application_name', "%{$search}%")
                ->orWhereLike('server_name', "%{$search}%")
                ->orWhereLike('commit', "%{$search}%")
                ->orWhereLike('commit_message', "%{$search}%")))
            ->when($this->project !== 'all', fn (Builder $query) => $query->whereIn('application_id', $this->projectApplicationIds()))
            ->when($this->server !== 'all', fn (Builder $query) => $query->where('server_id', (int) $this->server))
            ->when($this->source !== 'all', fn (Builder $query) => $query->where('git_type', $this->source))
            ->when($this->status !== 'all', fn (Builder $query) => $query->where('status', $this->status))
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

    /**
     * application_id is varchar, so a whereHas join to applications.id (bigint) fails on PostgreSQL.
     *
     * @return Collection<int, string>
     */
    private function projectApplicationIds(): Collection
    {
        return Application::withTrashed()
            ->withoutGlobalScope('withRelations')
            ->whereRelation('environment', 'project_id', (int) $this->project)
            ->pluck('id')
            ->map(fn ($id) => (string) $id);
    }
}
