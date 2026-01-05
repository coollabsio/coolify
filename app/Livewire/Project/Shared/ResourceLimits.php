<?php

namespace App\Livewire\Project\Shared;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class ResourceLimits extends Component
{
    use AuthorizesRequests;

    public $resource;

    // Explicit properties
    public ?string $limitsCpus = null;

    public ?string $limitsCpuset = null;

    public ?int $limitsCpuShares = null;

    public string $limitsMemory;

    public string $limitsMemorySwap;

    public int $limitsMemorySwappiness;

    public string $limitsMemoryReservation;

    protected $rules = [
        'limitsMemory' => 'required|string',
        'limitsMemorySwap' => 'required|string',
        'limitsMemorySwappiness' => 'required|integer|min:0|max:100',
        'limitsMemoryReservation' => 'required|string',
        'limitsCpus' => 'nullable',
        'limitsCpuset' => 'nullable',
        'limitsCpuShares' => 'nullable',
    ];

    protected $validationAttributes = [
        'limitsMemory' => 'memory',
        'limitsMemorySwap' => 'swap',
        'limitsMemorySwappiness' => 'swappiness',
        'limitsMemoryReservation' => 'reservation',
        'limitsCpus' => 'cpus',
        'limitsCpuset' => 'cpuset',
        'limitsCpuShares' => 'cpu shares',
    ];

    /**
     * Sync data between component properties and model
     *
     * @param  bool  $toModel  If true, sync FROM properties TO model. If false, sync FROM model TO properties.
     */
    private function syncData(bool $toModel = false): void
    {
        if ($toModel) {
            // Sync TO model (before save)
            $this->resource->limits_cpus = $this->limitsCpus;
            $this->resource->limits_cpuset = $this->limitsCpuset;
            $this->resource->limits_cpu_shares = $this->limitsCpuShares;
            $this->resource->limits_memory = $this->limitsMemory;
            $this->resource->limits_memory_swap = $this->limitsMemorySwap;
            $this->resource->limits_memory_swappiness = $this->limitsMemorySwappiness;
            $this->resource->limits_memory_reservation = $this->limitsMemoryReservation;
        } else {
            // Sync FROM model (on load/refresh)
            $this->limitsCpus = $this->resource->limits_cpus;
            $this->limitsCpuset = $this->resource->limits_cpuset;
            $this->limitsCpuShares = $this->resource->limits_cpu_shares;
            $this->limitsMemory = $this->resource->limits_memory;
            $this->limitsMemorySwap = $this->resource->limits_memory_swap;
            $this->limitsMemorySwappiness = $this->resource->limits_memory_swappiness;
            $this->limitsMemoryReservation = $this->resource->limits_memory_reservation;
        }
    }

    public function mount()
    {
        $this->syncData(false);
    }

    public function submit()
    {
        try {
            $this->authorize('update', $this->resource);

            // Apply default values to properties
            if (! $this->limitsMemory) {
                $this->limitsMemory = '0';
            }
            if (! $this->limitsMemorySwap) {
                $this->limitsMemorySwap = '0';
            }
            if (is_null($this->limitsMemorySwappiness)) {
                $this->limitsMemorySwappiness = 60;
            }
            if (! $this->limitsMemoryReservation) {
                $this->limitsMemoryReservation = '0';
            }
            if (! $this->limitsCpus) {
                $this->limitsCpus = '0';
            }
            if ($this->limitsCpuset === '') {
                $this->limitsCpuset = null;
            }
            if (is_null($this->limitsCpuShares)) {
                $this->limitsCpuShares = 1024;
            }

            $this->validate();

            $this->syncData(true);
            $this->resource->save();
            $this->dispatch('success', 'Resource limits updated.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
