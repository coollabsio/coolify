<?php

namespace App\Livewire\Project\Shared;

use App\Enums\ProxyTypes;
use App\Models\Application;
use App\Models\Server;
use App\Models\Service;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

/**
 * Maintenance mode of an application or service: the proxy shows a maintenance page with
 * status 503 instead of the resource, and the containers keep running.
 */
class Maintenance extends Component
{
    use AuthorizesRequests;

    public Application|Service $resource;

    public bool $isMaintenanceEnabled = false;

    public ?string $maintenancePage = null;

    public function mount(): void
    {
        $this->isMaintenanceEnabled = (bool) $this->resource->is_maintenance_enabled;
        $this->maintenancePage = $this->resource->maintenance_page;
    }

    /**
     * Servers that run the resource with a supported proxy (Traefik or Caddy, not Swarm).
     */
    public function getIsSupportedProperty(): bool
    {
        return $this->resource->maintenanceServers()->contains(fn (Server $server) => ! $server->isSwarm()
            && in_array($server->proxyType(), [ProxyTypes::TRAEFIK->value, ProxyTypes::CADDY->value], true));
    }

    public function instantSaveMaintenance(): void
    {
        try {
            $this->authorize('update', $this->resource);
            $this->resource->is_maintenance_enabled = $this->isMaintenanceEnabled;
            $this->resource->save();
            $this->resource->syncMaintenancePage();
            $this->dispatch('success', $this->isMaintenanceEnabled ? 'Maintenance mode enabled.' : 'Maintenance mode disabled.');
        } catch (\Throwable $e) {
            $this->isMaintenanceEnabled = (bool) $this->resource->fresh()?->is_maintenance_enabled;
            handleError($e, $this);
        }
    }

    public function submit(): void
    {
        try {
            $this->authorize('update', $this->resource);
            $this->validate([
                'maintenancePage' => ['nullable', 'string', proxyPageSizeRule('maintenance page')],
            ]);
            $this->resource->maintenance_page = filled($this->maintenancePage) ? $this->maintenancePage : null;
            $this->resource->save();
            if ($this->resource->is_maintenance_enabled) {
                $this->resource->syncMaintenancePage();
            }
            $this->dispatch('success', 'Maintenance page saved.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function resetMaintenancePage(): void
    {
        try {
            $this->authorize('update', $this->resource);
            $this->resource->maintenance_page = null;
            $this->resource->save();
            $this->maintenancePage = null;
            if ($this->resource->is_maintenance_enabled) {
                $this->resource->syncMaintenancePage();
            }
            $this->dispatch('success', 'Default maintenance page restored.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.project.shared.maintenance');
    }
}
