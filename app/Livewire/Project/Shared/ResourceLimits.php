<?php

namespace App\Livewire\Project\Shared;

use Livewire\Component;

class ResourceLimits extends Component
{
    public $resource;

    protected $rules = [
        'resource.limits_memory' => 'required|string',
        'resource.limits_memory_swap' => 'required|string',
        'resource.limits_memory_swappiness' => 'required|integer|min:0|max:100',
        'resource.limits_memory_reservation' => 'required|string',
        'resource.limits_cpus' => 'nullable',
        'resource.limits_cpuset' => 'nullable',
        'resource.limits_cpu_shares' => 'nullable',
    ];

    protected $validationAttributes = [
        'resource.limits_memory' => 'memory',
        'resource.limits_memory_swap' => 'swap',
        'resource.limits_memory_swappiness' => 'swappiness',
        'resource.limits_memory_reservation' => 'reservation',
        'resource.limits_cpus' => 'cpus',
        'resource.limits_cpuset' => 'cpuset',
        'resource.limits_cpu_shares' => 'cpu shares',
    ];

    public function submit()
    {
        try {
            if (! $this->resource->limits_memory) {
                $this->resource->limits_memory = '0';
            }
            if (! $this->resource->limits_memory_swap) {
                $this->resource->limits_memory_swap = '0';
            }
            if (is_null($this->resource->limits_memory_swappiness)) {
                $this->resource->limits_memory_swappiness = '60';
            }
            if (! $this->resource->limits_memory_reservation) {
                $this->resource->limits_memory_reservation = '0';
            }
            if (! $this->resource->limits_cpus) {
                $this->resource->limits_cpus = '0';
            }
            if ($this->resource->limits_cpuset === '') {
                $this->resource->limits_cpuset = null;
            }
            if (is_null($this->resource->limits_cpu_shares)) {
                $this->resource->limits_cpu_shares = 1024;
            }
            $this->validate();
            $this->resource->save();
            $this->dispatch('success', 'Resource limits updated.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
