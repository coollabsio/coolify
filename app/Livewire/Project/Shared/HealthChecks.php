<?php

namespace App\Livewire\Project\Shared;

use App\Livewire\Concerns\SynchronizesModelData;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class HealthChecks extends Component
{
    use AuthorizesRequests;
    use SynchronizesModelData;

    public $resource;

    // Explicit properties
    public bool $healthCheckEnabled = false;

    public string $healthCheckMethod;

    public string $healthCheckScheme;

    public string $healthCheckHost;

    public ?string $healthCheckPort = null;

    public string $healthCheckPath;

    public int $healthCheckReturnCode;

    public ?string $healthCheckResponseText = null;

    public int $healthCheckInterval;

    public int $healthCheckTimeout;

    public int $healthCheckRetries;

    public int $healthCheckStartPeriod;

    public bool $customHealthcheckFound = false;

    protected $rules = [
        'healthCheckEnabled' => 'boolean',
        'healthCheckPath' => 'string',
        'healthCheckPort' => 'nullable|string',
        'healthCheckHost' => 'string',
        'healthCheckMethod' => 'string',
        'healthCheckReturnCode' => 'integer',
        'healthCheckScheme' => 'string',
        'healthCheckResponseText' => 'nullable|string',
        'healthCheckInterval' => 'integer|min:1',
        'healthCheckTimeout' => 'integer|min:1',
        'healthCheckRetries' => 'integer|min:1',
        'healthCheckStartPeriod' => 'integer',
        'customHealthcheckFound' => 'boolean',
    ];

    protected function getModelBindings(): array
    {
        return [
            'healthCheckEnabled' => 'resource.health_check_enabled',
            'healthCheckMethod' => 'resource.health_check_method',
            'healthCheckScheme' => 'resource.health_check_scheme',
            'healthCheckHost' => 'resource.health_check_host',
            'healthCheckPort' => 'resource.health_check_port',
            'healthCheckPath' => 'resource.health_check_path',
            'healthCheckReturnCode' => 'resource.health_check_return_code',
            'healthCheckResponseText' => 'resource.health_check_response_text',
            'healthCheckInterval' => 'resource.health_check_interval',
            'healthCheckTimeout' => 'resource.health_check_timeout',
            'healthCheckRetries' => 'resource.health_check_retries',
            'healthCheckStartPeriod' => 'resource.health_check_start_period',
            'customHealthcheckFound' => 'resource.custom_healthcheck_found',
        ];
    }

    public function mount()
    {
        $this->authorize('view', $this->resource);
        $this->syncFromModel();
    }

    public function instantSave()
    {
        $this->authorize('update', $this->resource);

        $this->syncToModel();
        $this->resource->save();
        $this->dispatch('success', 'Health check updated.');
    }

    public function submit()
    {
        try {
            $this->authorize('update', $this->resource);
            $this->validate();

            $this->syncToModel();
            $this->resource->save();
            $this->dispatch('success', 'Health check updated.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function toggleHealthcheck()
    {
        try {
            $this->authorize('update', $this->resource);
            $wasEnabled = $this->healthCheckEnabled;
            $this->healthCheckEnabled = ! $this->healthCheckEnabled;

            $this->syncToModel();
            $this->resource->save();

            if ($this->healthCheckEnabled && ! $wasEnabled && $this->resource->isRunning()) {
                $this->dispatch('info', 'Health check has been enabled. A restart is required to apply the new settings.');
            } else {
                $this->dispatch('success', 'Health check '.($this->healthCheckEnabled ? 'enabled' : 'disabled').'.');
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.project.shared.health-checks');
    }
}
