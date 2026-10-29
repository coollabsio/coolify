<?php

namespace App\Livewire\Team;

use App\Models\AuditEvent;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class AuditLog extends Component
{
    use WithPagination;

    public string $search = '';

    public string $action = 'all';

    public string $source = 'all';

    public int $perPage = 25;

    #[Locked]
    public string $sort = 'time-desc';

    /** @var array<string, string> sort value prefix => audit_events column */
    private const SORT_COLUMNS = [
        'time' => 'created_at',
        'actor' => 'actor_name',
        'activity' => 'description',
        'source' => 'source',
    ];

    public function boot(): void
    {
        $team = currentTeam();

        abort_unless($team && auth()->user()->isAdminOfTeam($team->id), 403);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedAction(): void
    {
        $this->resetPage();
    }

    public function updatedSource(): void
    {
        $this->resetPage();
    }

    public function setSort(string $sort): void
    {
        [$column, $direction] = array_pad(explode('-', $sort, 2), 2, null);

        if (! array_key_exists($column, self::SORT_COLUMNS) || ! in_array($direction, ['asc', 'desc'], true)) {
            return;
        }

        $this->sort = $sort;
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->perPage = max(10, min(100, $this->perPage));
        $this->resetPage();
    }

    public function render(): View
    {
        $search = trim($this->search);
        $teamId = currentTeam()->id;
        $canViewInstanceEvents = $teamId === 0 && isInstanceAdmin();
        $visibleEvents = AuditEvent::query()->visibleToTeam($teamId, $canViewInstanceEvents);
        $actionOptions = [
            ['value' => 'all', 'label' => 'All actions'],
            ...$visibleEvents->clone()
                ->select('action')
                ->distinct()
                ->orderBy('action')
                ->pluck('action')
                ->map(fn (string $action): array => ['value' => $action, 'label' => Str::headline($action)])
                ->all(),
        ];
        $events = AuditEvent::query()
            ->visibleToTeam($teamId, $canViewInstanceEvents)
            ->filtered($search, $this->action, $this->source)
            ->when($this->sort !== 'time-desc', function ($query): void {
                [$column, $direction] = explode('-', $this->sort, 2);
                $query->orderBy(self::SORT_COLUMNS[$column], $direction);
            })
            ->latestFirst()
            ->paginate($this->perPage);

        return view('livewire.team.audit-log', ['actionOptions' => $actionOptions, 'events' => $events]);
    }
}
