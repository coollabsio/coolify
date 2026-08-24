<?php

namespace App\Livewire\Team;

use App\Models\AuditEvent;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class AuditLog extends Component
{
    use WithPagination;

    public string $search = '';

    public string $action = 'all';

    public string $source = 'all';

    public int $perPage = 25;

    public function mount(): void
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
        $events = AuditEvent::query()
            ->where(function ($query) use ($canViewInstanceEvents, $teamId): void {
                $query->where('team_id', $teamId)
                    ->when($canViewInstanceEvents, fn ($query) => $query->orWhereNull('team_id'));
            })
            ->when($this->action !== 'all', fn ($query) => $query->where('action', $this->action))
            ->when($this->source !== 'all', fn ($query) => $query->where('source', $this->source))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('description', 'like', "%{$search}%")
                        ->orWhere('resource_name', 'like', "%{$search}%")
                        ->orWhere('actor_name', 'like', "%{$search}%")
                        ->orWhere('actor_email', 'like', "%{$search}%")
                        ->orWhere('event', 'like', "%{$search}%");
                });
            })
            ->latest('created_at')
            ->latest('id')
            ->paginate($this->perPage);

        return view('livewire.team.audit-log', ['events' => $events]);
    }
}
