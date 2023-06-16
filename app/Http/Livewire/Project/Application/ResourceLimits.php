<?php

namespace App\Http\Livewire\Project\Application;

use App\Models\Application;
use Livewire\Component;

class ResourceLimits extends Component
{
    public Application $application;
    protected $rules = [
        'application.limits_memory' => 'required|string',
        'application.limits_memory_swap' => 'required|string',
        'application.limits_memory_swappiness' => 'required|integer|min:0|max:100',
        'application.limits_memory_reservation' => 'required|string',
        'application.limits_cpus' => 'nullable',
        'application.limits_cpuset' => 'nullable',
        'application.limits_cpu_shares' => 'nullable',
    ];
    protected $validationAttributes = [
        'application.limits_memory' => 'memory',
        'application.limits_memory_swap' => 'swap',
        'application.limits_memory_swappiness' => 'swappiness',
        'application.limits_memory_reservation' => 'reservation',
        'application.limits_cpus' => 'cpus',
        'application.limits_cpuset' => 'cpuset',
        'application.limits_cpu_shares' => 'cpu shares',
    ];
    public function submit()
    {
        try {
            if (!$this->application->limits_memory) {
                $this->application->limits_memory = "0";
            }
            if (!$this->application->limits_memory_swap) {
                $this->application->limits_memory_swap = "0";
            }
            if (!$this->application->limits_memory_swappiness) {
                $this->application->limits_memory_swappiness = "60";
            }
            if (!$this->application->limits_memory_reservation) {
                $this->application->limits_memory_reservation = "0";
            }
            if (!$this->application->limits_cpus) {
                $this->application->limits_cpus = "0";
            }
            if (!$this->application->limits_cpuset) {
                $this->application->limits_cpuset = "0";
            }
            if (!$this->application->limits_cpu_shares) {
                $this->application->limits_cpu_shares = 1024;
            }
            $this->validate();
            $this->application->save();
        } catch (\Exception $e) {
            return general_error_handler(err: $e, that: $this);
        }
    }
}
