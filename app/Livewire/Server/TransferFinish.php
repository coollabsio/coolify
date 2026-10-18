<?php

namespace App\Livewire\Server;

use App\Jobs\CheckTransferFinishStepsJob;
use App\Models\Server;
use App\Services\ServerTransfer\TransferFinishChecklist;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The "Finish the transfer" list on the server page. A separate component, so its refresh does not re-render the
 * server form.
 */
class TransferFinish extends Component
{
    use AuthorizesRequests;

    public Server $server;

    public function getListeners(): array
    {
        return [
            "echo-private:team.{$this->server->team_id},TransferFinishChanged" => 'handleTransferFinishChanged',
        ];
    }

    /**
     * The server page sends this after a take back, which starts a new list.
     */
    #[On('transferFinishStarted')]
    public function reloadServer(): void
    {
        $this->server->refresh();
    }

    /**
     * A background check, or a user in another tab, changed a step of this list.
     */
    public function handleTransferFinishChanged(array $event): void
    {
        if (($event['serverUuid'] ?? null) === $this->server->uuid) {
            $this->server->refresh();
        }
    }

    /**
     * Start the background checks when they are stale. Called when the panel loads and by its poll.
     */
    public function refreshSteps(TransferFinishChecklist $checklist): void
    {
        $this->server->refresh();
        if (auth()->user()?->can('update', $this->server)) {
            $checklist->checkWhenStale($this->server);
        }
    }

    public function checkAllNow(): void
    {
        $this->authorize('update', $this->server);
        CheckTransferFinishStepsJob::dispatch($this->server);
    }

    public function checkStep(TransferFinishChecklist $checklist, string $key): void
    {
        $this->authorize('update', $this->server);
        $checklist->check($this->server, $key);
    }

    public function fixStep(TransferFinishChecklist $checklist, string $key): void
    {
        $this->authorize('update', $this->server);
        $item = $checklist->fix($this->server, $key);
        $toast = match ($item['status']) {
            'ok' => 'success',
            'pending' => 'info',
            default => 'error',
        };
        $this->dispatch($toast, $item['message'] ?? 'Done.');
    }

    public function markStepDone(TransferFinishChecklist $checklist, string $key): void
    {
        $this->authorize('update', $this->server);
        $checklist->markDone($this->server, $key);
    }

    public function finishTransfer(TransferFinishChecklist $checklist): void
    {
        $this->authorize('update', $this->server);
        $skipped = count($checklist->openItems($this->server));
        $checklist->finish($this->server, auth()->user());

        auditLog('ui.server.transfer_finished', [
            'team_id' => $this->server->team_id,
            'server_uuid' => $this->server->uuid,
            'skipped_steps' => $skipped,
        ]);

        $this->dispatch('success', 'The transfer is finished.');
    }

    public function render()
    {
        $checklist = app(TransferFinishChecklist::class);
        $finish = $checklist->isOpen($this->server) ? $checklist->of($this->server) : null;
        $items = collect($finish['items'] ?? []);
        $openItems = $items->filter(fn (array $item) => in_array($item['status'], TransferFinishChecklist::OPEN_STATUSES, true));

        return view('livewire.server.transfer-finish', [
            'checklist' => $checklist,
            'finish' => $finish,
            'openGroups' => collect(TransferFinishChecklist::GROUPS)
                ->map(fn (string $label, string $group) => ['label' => $label, 'items' => $openItems->filter(fn (array $item) => $checklist->groupOf($item) === $group)->values()])
                ->filter(fn (array $group) => $group['items']->isNotEmpty()),
            'doneItems' => $items->reject(fn (array $item) => in_array($item['status'], TransferFinishChecklist::OPEN_STATUSES, true))->values(),
            'openCount' => $openItems->count(),
            'totalCount' => $items->count(),
        ]);
    }
}
