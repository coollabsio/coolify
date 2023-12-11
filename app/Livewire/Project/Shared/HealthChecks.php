<?php

namespace App\Livewire\Project\Shared;

use Livewire\Component;

class HealthChecks extends Component
{

    public $resource;
    protected $rules = [
        'resource.health_check_enabled' => 'boolean',
        'resource.health_check_path' => 'string',
        'resource.health_check_port' => 'nullable|string',
        'resource.health_check_host' => 'string',
        'resource.health_check_method' => 'string',
        'resource.health_check_return_code' => 'integer',
        'resource.health_check_scheme' => 'string',
        'resource.health_check_response_text' => 'nullable|string',
        'resource.health_check_interval' => 'integer',
        'resource.health_check_timeout' => 'integer',
        'resource.health_check_retries' => 'integer',
        'resource.health_check_start_period' => 'integer',

    ];
    public function instantSave()
    {
        $this->resource->save();
        $this->dispatch('success', 'Health check updated.');


    }
    public function submit()
    {
        try {
            $this->validate();
            $this->resource->save();
            $this->dispatch('success', 'Health check updated.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
    public function render()
    {
        return view('livewire.project.shared.health-checks');
    }
}
