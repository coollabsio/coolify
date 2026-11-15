<?php

namespace App\Livewire\Project\ClusterApplication;

use App\Models\NodeWorkload;
use App\Services\Dns\NodeWorkloadDnsRecords;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The managed DNS records of the domains of a cluster application (decision 0019), on its Domains page:
 * the zone, the records, and their owner for each hostname. Zones and records come from the workload's team.
 */
class DomainDns extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public NodeWorkload $workload;

    public function mount(NodeWorkload $workload): void
    {
        $this->authorize('view', $workload);
        $this->workload = $workload;
    }

    #[On('clusterDomainsChanged')]
    public function refreshDomains(): void
    {
        $this->workload->refresh();
    }

    /** Creates the missing records of one hostname in one of the zones that manage it. */
    public function createRecords(string $hostname, int $zoneId): void
    {
        $this->authorize('update', $this->workload);
        $this->workload->refresh();
        $hostname = strtolower($hostname);
        $records = app(NodeWorkloadDnsRecords::class);
        $zone = in_array($hostname, $this->workload->dnsHostnames(), true)
            ? $records->zones($this->workload, $hostname)->firstWhere('id', $zoneId)
            : null;
        if ($zone === null) {
            $this->dispatch('error', 'No connected DNS provider of the team manages this domain.');

            return;
        }

        $problem = $records->create($this->workload, $hostname, $zone);
        $problem === null
            ? $this->dispatch('success', "DNS records created for {$hostname}.")
            : $this->dispatch('error', $problem);
    }

    public function render(): View
    {
        return view('livewire.project.cluster-application.domain-dns', app(NodeWorkloadDnsRecords::class)->status($this->workload));
    }
}
