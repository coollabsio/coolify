<?php

namespace App\Livewire\Fleet;

use App\Models\Server;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Lazy]
class Metrics extends Component
{
    public string $chartId = 'fleet-metrics';

    /** Metrics-enabled servers owned by the current team. */
    public Collection $servers;

    /** @var array<string, string> uuid => name */
    public array $serverOptions = [];

    #[Url(as: 'range')]
    public string $range = '24h';

    #[Url(as: 'server')]
    public string $serverUuid = '';

    public bool $live = false;

    public function mount(): void
    {
        $allServers = Server::ownedByCurrentTeamCached();

        $this->servers = $allServers
            ->filter(fn (Server $server) => $server->isMetricsEnabled())
            ->values();

        $this->serverOptions = $this->servers
            ->mapWithKeys(fn (Server $server) => [$server->uuid => $server->name])
            ->all();

        if ($this->serverUuid !== '' && ! array_key_exists($this->serverUuid, $this->serverOptions)) {
            $this->serverUuid = '';
        }
    }

    public function placeholder(): View
    {
        return view('livewire.fleet.metrics-placeholder');
    }

    public function render(): View
    {
        return view('livewire.fleet.metrics');
    }
}
