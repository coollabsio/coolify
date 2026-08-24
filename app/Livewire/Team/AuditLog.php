<?php

namespace App\Livewire\Team;

use App\Models\AuditEvent;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithPagination;

class AuditLog extends Component
{
    use WithPagination;

    public string $search = '';

    public string $action = 'all';

    public string $source = 'all';

    public int $perPage = 25;

    public function boot(): void
    {
        abort_unless(auth()->user()->isAdminOfTeam(currentTeam()->id), 403);
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
            ->latestFirst()
            ->paginate($this->perPage);

        return view('livewire.team.audit-log', ['actionOptions' => $actionOptions, 'events' => $events]);
    }
}
