<?php

namespace App\Livewire\Project\Shared;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Validate;
use Livewire\Component;

class HealthChecks extends Component
{
    use AuthorizesRequests;

    public $resource;

    // Explicit properties
    #[Validate(['boolean'])]
    public bool $healthCheckEnabled = false;

    #[Validate(['string'])]
    public string $healthCheckMethod;

    #[Validate(['string'])]
    public string $healthCheckScheme;

    #[Validate(['string'])]
    public string $healthCheckHost;

    #[Validate(['nullable', 'string'])]
    public ?string $healthCheckPort = null;

    #[Validate(['string'])]
    public string $healthCheckPath;

    #[Validate(['integer'])]
    public int $healthCheckReturnCode;

    #[Validate(['nullable', 'string'])]
    public ?string $healthCheckResponseText = null;

    #[Validate(['integer', 'min:1'])]
    public int $healthCheckInterval;

    #[Validate(['integer', 'min:1'])]
    public int $healthCheckTimeout;

    #[Validate(['integer', 'min:1'])]
    public int $healthCheckRetries;

    #[Validate(['integer'])]
    public int $healthCheckStartPeriod;

    #[Validate(['boolean'])]
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

    public function mount()
    {
        $this->authorize('view', $this->resource);
        $this->syncData();
    }

    public function syncData(bool $toModel = false): void
    {
        if ($toModel) {
            $this->validate();

            // Sync to model
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

            $this->resource->save();
        } else {
            // Sync from model
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

    public function instantSave()
    {
        $this->authorize('update', $this->resource);

        // Sync component properties to model
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
        $this->resource->save();
        $this->dispatch('success', 'Health check updated.');
    }

    public function submit()
    {
        try {
            $this->authorize('update', $this->resource);
            $this->validate();

            // Sync component properties to model
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

            // Sync component properties to model
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
