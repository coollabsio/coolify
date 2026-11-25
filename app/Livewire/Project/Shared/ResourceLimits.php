<?php

namespace App\Livewire\Project\Shared;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class ResourceLimits extends Component
{
    use AuthorizesRequests;

    public $resource;

    // Explicit properties
    public ?string $limitsCpus = null;

    public ?string $limitsCpuset = null;

    public mixed $limitsCpuShares = null;

    public string $limitsMemory;

    public string $limitsMemorySwap;

    public mixed $limitsMemorySwappiness = 0;

    public string $limitsMemoryReservation;

    protected $rules = [
        'limitsMemory' => ['required', 'string', 'regex:/^(0|\d+[bBkKmMgG])$/'],
        'limitsMemorySwap' => ['required', 'string', 'regex:/^(0|\d+[bBkKmMgG])$/'],
        'limitsMemorySwappiness' => 'required|integer|min:0|max:100',
        'limitsMemoryReservation' => ['required', 'string', 'regex:/^(0|\d+[bBkKmMgG])$/'],
        'limitsCpus' => ['nullable', 'regex:/^\d*\.?\d+$/'],
        'limitsCpuset' => ['nullable', 'regex:/^\d+([,-]\d+)*$/'],
        'limitsCpuShares' => 'nullable|integer|min:0',
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

    protected $messages = [
        'limitsMemory.regex' => 'Maximum Memory Limit must be a number followed by a unit (b, k, m, g). Example: 256m, 1g. Use 0 for unlimited.',
        'limitsMemorySwap.regex' => 'Maximum Swap Limit must be a number followed by a unit (b, k, m, g). Example: 256m, 1g. Use 0 for unlimited.',
        'limitsMemoryReservation.regex' => 'Soft Memory Limit must be a number followed by a unit (b, k, m, g). Example: 256m, 1g. Use 0 for unlimited.',
        'limitsCpus.regex' => 'Number of CPUs must be a number (integer or decimal). Example: 0.5, 2.',
        'limitsCpuset.regex' => 'CPU sets must be a comma-separated list of CPU numbers or ranges. Example: 0-2 or 0,1,3.',
        'limitsMemorySwappiness.integer' => 'Swappiness must be a whole number between 0 and 100.',
        'limitsMemorySwappiness.min' => 'Swappiness must be between 0 and 100.',
        'limitsMemorySwappiness.max' => 'Swappiness must be between 0 and 100.',
        'limitsCpuShares.integer' => 'CPU Weight must be a whole number.',
        'limitsCpuShares.min' => 'CPU Weight must be a positive number.',
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
            $this->resource->limits_cpu_shares = (int) $this->limitsCpuShares;
            $this->resource->limits_memory = $this->limitsMemory;
            $this->resource->limits_memory_swap = $this->limitsMemorySwap;
            $this->resource->limits_memory_swappiness = (int) $this->limitsMemorySwappiness;
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
            if ($this->limitsMemorySwappiness === '' || is_null($this->limitsMemorySwappiness)) {
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
            if ($this->limitsCpuShares === '' || is_null($this->limitsCpuShares)) {
                $this->limitsCpuShares = 1024;
            }

            $this->validate();

            $this->syncData(true);
            $this->resource->save();
            $this->dispatch('success', 'Resource limits updated.');
        } catch (ValidationException $e) {
            foreach ($e->validator->errors()->all() as $message) {
                $this->dispatch('error', $message);
            }

            return;
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
