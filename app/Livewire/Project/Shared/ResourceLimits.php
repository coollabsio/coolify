<?php

namespace App\Livewire\Project\Shared;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class ResourceLimits extends Component
{
    use AuthorizesRequests;

    // Default values for resource limits
    private const DEFAULT_CPU_LIMIT = 0.0;
    private const DEFAULT_CPU_SET = '0';
    private const DEFAULT_CPU_SHARES = 1024;
    private const DEFAULT_MEMORY_SWAPPINESS = 60;
    private const DEFAULT_MEMORY_LIMIT = '0';
    private const DEFAULT_MEMORY_SWAP = '0';
    private const DEFAULT_MEMORY_RESERVATION = '0';

    public mixed $resource;

    public ?float $limitsCpus = null;
    public ?string $limitsCpuset = null;
    public ?int $limitsCpuShares = null;
    public ?string $limitsMemory = null;
    public ?string $limitsMemorySwap = null;
    public ?int $limitsMemorySwappiness = null;
    public ?string $limitsMemoryReservation = null;

    protected $rules = [
        'limitsMemory' => 'nullable|string',
        'limitsMemorySwap' => 'nullable|string',
        'limitsMemorySwappiness' => 'nullable|integer|min:0|max:100',
        'limitsMemoryReservation' => 'nullable|string',
        'limitsCpus' => 'nullable|numeric|min:0|max:1024',
        'limitsCpuset' => 'nullable|string',
        'limitsCpuShares' => 'nullable|integer|min:0|max:8192',
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
    private function syncData(bool $toModel): void
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

            return;
        }

        // Sync FROM model (on load/refresh)
        $this->limitsCpus = $this->resource->limits_cpus;
        $this->limitsCpuset = $this->resource->limits_cpuset;
        $this->limitsCpuShares = $this->resource->limits_cpu_shares;
        $this->limitsMemory = $this->resource->limits_memory;
        $this->limitsMemorySwap = $this->resource->limits_memory_swap;
        $this->limitsMemorySwappiness = $this->resource->limits_memory_swappiness;
        $this->limitsMemoryReservation = $this->resource->limits_memory_reservation;

        // Convert default values to null so UI shows placeholders instead of defaults
        if ($this->limitsCpus === self::DEFAULT_CPU_LIMIT) {
            $this->limitsCpus = null;
        }
        if ($this->limitsCpuset === self::DEFAULT_CPU_SET) {
            $this->limitsCpuset = null;
        }
        if ($this->limitsCpuShares === self::DEFAULT_CPU_SHARES) {
            $this->limitsCpuShares = null;
        }
        if ($this->limitsMemorySwappiness === self::DEFAULT_MEMORY_SWAPPINESS) {
            $this->limitsMemorySwappiness = null;
        }
        if ($this->limitsMemory === self::DEFAULT_MEMORY_LIMIT) {
            $this->limitsMemory = null;
        }
        if ($this->limitsMemorySwap === self::DEFAULT_MEMORY_SWAP) {
            $this->limitsMemorySwap = null;
        }
        if ($this->limitsMemoryReservation === self::DEFAULT_MEMORY_RESERVATION) {
            $this->limitsMemoryReservation = null;
        }
    }

    public function mount(): void
    {
        $this->syncData(toModel: false);
    }

    public function submit(): void
    {
        try {
            $this->authorize('update', $this->resource);

            // Apply defaults for empty fields
            if (empty($this->limitsMemory)) {
                $this->limitsMemory = self::DEFAULT_MEMORY_LIMIT;
            }
            if (empty($this->limitsMemorySwap)) {
                $this->limitsMemorySwap = self::DEFAULT_MEMORY_SWAP;
            }
            if (empty($this->limitsMemoryReservation)) {
                $this->limitsMemoryReservation = self::DEFAULT_MEMORY_RESERVATION;
            }
            if ($this->limitsCpus === null) {
                $this->limitsCpus = self::DEFAULT_CPU_LIMIT;
            }
            if (empty($this->limitsCpuset)) {
                $this->limitsCpuset = self::DEFAULT_CPU_SET;
            }
            if ($this->limitsCpuShares === null) {
                $this->limitsCpuShares = self::DEFAULT_CPU_SHARES;
            }
            if ($this->limitsMemorySwappiness === null) {
                $this->limitsMemorySwappiness = self::DEFAULT_MEMORY_SWAPPINESS;
            }

            $this->validate();

            $this->syncData(toModel: true);
            $this->resource->save();

            // Reload from model to convert defaults back to null for placeholder display
            $this->syncData(toModel: false);

            $this->dispatch('success', 'Resource limits updated.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }
}
