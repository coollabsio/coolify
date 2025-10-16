<?php

namespace App\Livewire\Project\Shared;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class HealthChecks extends Component
{
    use AuthorizesRequests;

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

    /**
     * Sync data between component properties and model
     *
     * @param  bool  $toModel  If true, sync FROM properties TO model. If false, sync FROM model TO properties.
     */
    private function syncData(bool $toModel = false): void
    {
        if ($toModel) {
            // Sync TO model (before save)
            $this->resource->health_check_enabled = $this->healthCheckEnabled;
            $this->resource->health_check_method = $this->healthCheckMethod;
            $this->resource->health_check_scheme = $this->healthCheckScheme;
            $this->resource->health_check_host = $this->healthCheckHost;
            $this->resource->health_check_port = $this->healthCheckPort;
            $this->resource->health_check_path = $this->healthCheckPath;
            $this->resource->health_check_return_code = $this->healthCheckReturnCode;
            $this->resource->health_check_response_text = $this->healthCheckResponseText;
            $this->resource->health_check_interval = $this->healthCheckInterval;
            $this->resource->health_check_timeout = $this->healthCheckTimeout;
            $this->resource->health_check_retries = $this->healthCheckRetries;
            $this->resource->health_check_start_period = $this->healthCheckStartPeriod;
            $this->resource->custom_healthcheck_found = $this->customHealthcheckFound;
        } else {
            // Sync FROM model (on load/refresh)
            $this->healthCheckEnabled = $this->resource->health_check_enabled;
            $this->healthCheckMethod = $this->resource->health_check_method;
            $this->healthCheckScheme = $this->resource->health_check_scheme;
            $this->healthCheckHost = $this->resource->health_check_host;
            $this->healthCheckPort = $this->resource->health_check_port;
            $this->healthCheckPath = $this->resource->health_check_path;
            $this->healthCheckReturnCode = $this->resource->health_check_return_code;
            $this->healthCheckResponseText = $this->resource->health_check_response_text;
            $this->healthCheckInterval = $this->resource->health_check_interval;
            $this->healthCheckTimeout = $this->resource->health_check_timeout;
            $this->healthCheckRetries = $this->resource->health_check_retries;
            $this->healthCheckStartPeriod = $this->resource->health_check_start_period;
            $this->customHealthcheckFound = $this->resource->custom_healthcheck_found;
        }
    }

    public function mount()
    {
        $this->authorize('view', $this->resource);
        $this->syncData(false);
    }

    public function instantSave()
    {
        $this->authorize('update', $this->resource);

        $this->syncData(true);
        $this->resource->save();
        $this->dispatch('success', 'Health check updated.');
    }

    public function submit()
    {
        try {
            $this->authorize('update', $this->resource);
            $this->validate();

            $this->syncData(true);
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

            $this->syncData(true);
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
