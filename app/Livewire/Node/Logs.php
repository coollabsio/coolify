<?php

namespace App\Livewire\Node;

use App\Actions\Sentinel\FetchNodeLogs;
use App\Models\Node;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Logs extends Component
{
    use AuthorizesRequests;

    /** @var array<string, string> */
    public const LEVEL_FILTERS = [
        'all' => 'All levels',
        'warn' => 'Warnings and errors',
        'error' => 'Errors only',
    ];

    /** @var list<int> */
    public const LIMITS = [50, 100, 200, 500];

    public Node $node;

    public string $source = 'sentinel';

    public string $level = 'all';

    public int $limit = FetchNodeLogs::DEFAULT_LIMIT;

    public bool $autoRefresh = false;

    /** @var list<array{timestamp_unix_ms: int, level: string, component: string, message: string, fields: array<string, string>}> */
    #[Locked]
    public array $events = [];

    #[Locked]
    public ?string $transport = null;

    #[Locked]
    public bool $truncated = false;

    #[Locked]
    public ?string $loadError = null;

    public function mount(string $node_uuid): void
    {
        abort_unless(isDev() && config('constants.sentinel.host_enabled', false), 404);
        $this->node = Node::query()
            ->where('uuid', $node_uuid)
            ->where('team_id', currentTeam()->id)
            ->firstOrFail();
        $this->authorize('manageSentinel', $this->node);
        $this->loadLogs();
    }

    public function refreshLogs(): void
    {
        $this->authorize('manageSentinel', $this->node);
        $this->loadLogs();
        if ($this->loadError !== null) {
            $this->dispatch('error', $this->loadError);
        }
    }

    public function pollLogs(): void
    {
        $this->authorize('manageSentinel', $this->node);
        if (! $this->autoRefresh) {
            return;
        }
        $this->loadLogs();
    }

    public function updatedSource(): void
    {
        $this->authorize('manageSentinel', $this->node);
        $this->loadLogs();
    }

    public function updatedLimit(): void
    {
        $this->authorize('manageSentinel', $this->node);
        $this->loadLogs();
    }

    public function updatedLevel(): void
    {
        $this->authorize('manageSentinel', $this->node);
        if (! array_key_exists($this->level, self::LEVEL_FILTERS)) {
            $this->level = 'all';
        }
    }

    public function toggleAutoRefresh(): void
    {
        $this->authorize('manageSentinel', $this->node);
        if ($this->autoRefresh) {
            $this->loadLogs();
        }
    }

    public function render(): View
    {
        return view('livewire.node.logs', [
            'sources' => FetchNodeLogs::SOURCES,
            'levelFilters' => self::LEVEL_FILTERS,
            'limits' => self::LIMITS,
            'visibleEvents' => $this->visibleEvents(),
        ]);
    }

    /**
     * @return list<array{timestamp_unix_ms: int, level: string, component: string, message: string, fields: array<string, string>}>
     */
    private function visibleEvents(): array
    {
        $allowed = match ($this->level) {
            'error' => ['error'],
            'warn' => ['error', 'warn'],
            default => null,
        };
        if ($allowed === null) {
            return $this->events;
        }

        return array_values(array_filter(
            $this->events,
            fn (array $event): bool => in_array($event['level'], $allowed, true),
        ));
    }

    private function loadLogs(): void
    {
        $this->validate([
            'source' => ['required', 'string', 'in:'.implode(',', array_keys(FetchNodeLogs::SOURCES))],
            'limit' => ['required', 'integer', 'in:'.implode(',', self::LIMITS)],
        ]);

        try {
            $result = FetchNodeLogs::run($this->node, $this->source, $this->limit);
            $this->events = $result['events'];
            $this->transport = $result['transport'];
            $this->truncated = $result['truncated'];
            $this->loadError = null;
        } catch (\Throwable $e) {
            $this->events = [];
            $this->transport = null;
            $this->truncated = false;
            $this->loadError = $e instanceof \RuntimeException && str_starts_with($e->getMessage(), 'Flux ')
                ? $e->getMessage()
                : 'Could not read logs from this Node.';
        }
    }
}
