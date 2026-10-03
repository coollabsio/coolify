<?php

namespace App\Livewire\Server;

use App\Actions\Server\StartLogDrain;
use App\Actions\Server\StopLogDrain;
use App\Models\Server;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Validate;
use Livewire\Component;

class LogDrains extends Component
{
    use AuthorizesRequests;

    public Server $server;

    #[Validate(['boolean'])]
    public bool $isLogDrainNewRelicEnabled = false;

    #[Validate(['boolean'])]
    public bool $isLogDrainCustomEnabled = false;

    #[Validate(['boolean'])]
    public bool $isLogDrainAxiomEnabled = false;

    #[Validate(['string', 'nullable', 'regex:/^[a-zA-Z0-9_\-\.]+$/'])]
    public ?string $logDrainNewRelicLicenseKey = null;

    #[Validate(['url', 'nullable'])]
    public ?string $logDrainNewRelicBaseUri = null;

    #[Validate(['string', 'nullable', 'regex:/^[a-zA-Z0-9_\-\.]+$/'])]
    public ?string $logDrainAxiomDatasetName = null;

    #[Validate(['string', 'nullable', 'regex:/^[a-zA-Z0-9_\-\.]+$/'])]
    public ?string $logDrainAxiomApiKey = null;

    #[Validate(['string', 'nullable'])]
    public ?string $logDrainCustomConfig = null;

    #[Validate(['string', 'nullable'])]
    public ?string $logDrainCustomConfigParser = null;

    #[Validate(['boolean'])]
    public bool $isLogDrainCloudwatchEnabled = false;

    #[Validate(['string', 'nullable', 'max:64', 'regex:/^[a-z]{2}(-[a-z]+)+-\d+$/'])]
    public ?string $logDrainCloudwatchRegion = null;

    #[Validate(['string', 'nullable', 'regex:/^[\.\-_\/#A-Za-z0-9]{1,512}$/'])]
    public ?string $logDrainCloudwatchGroup = null;

    #[Validate(['string', 'nullable', 'max:256', 'regex:/^[A-Za-z0-9_\-\.\/#]*$/'])]
    public ?string $logDrainCloudwatchStreamPrefix = null;

    public function mount(string $server_uuid)
    {
        try {
            $this->server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
            $this->syncData();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    private function syncDataNewRelic(bool $toModel = false): void
    {
        if ($toModel) {
            $this->server->settings->is_logdrain_newrelic_enabled = $this->isLogDrainNewRelicEnabled;
            $this->server->settings->logdrain_newrelic_license_key = $this->logDrainNewRelicLicenseKey;
            $this->server->settings->logdrain_newrelic_base_uri = $this->logDrainNewRelicBaseUri;
        } else {
            $this->isLogDrainNewRelicEnabled = $this->server->settings->is_logdrain_newrelic_enabled;
            $this->logDrainNewRelicLicenseKey = auth()->user()->can('update', $this->server)
                ? $this->server->settings->logdrain_newrelic_license_key
                : null;
            $this->logDrainNewRelicBaseUri = $this->server->settings->logdrain_newrelic_base_uri;
        }
    }

    private function syncDataAxiom(bool $toModel = false): void
    {
        if ($toModel) {
            $this->server->settings->is_logdrain_axiom_enabled = $this->isLogDrainAxiomEnabled;
            $this->server->settings->logdrain_axiom_dataset_name = $this->logDrainAxiomDatasetName;
            $this->server->settings->logdrain_axiom_api_key = $this->logDrainAxiomApiKey;
        } else {
            $this->isLogDrainAxiomEnabled = $this->server->settings->is_logdrain_axiom_enabled;
            $this->logDrainAxiomDatasetName = $this->server->settings->logdrain_axiom_dataset_name;
            $this->logDrainAxiomApiKey = auth()->user()->can('update', $this->server)
                ? $this->server->settings->logdrain_axiom_api_key
                : null;
        }
    }

    private function syncDataCustom(bool $toModel = false): void
    {
        if ($toModel) {
            $this->server->settings->is_logdrain_custom_enabled = $this->isLogDrainCustomEnabled;
            $this->server->settings->logdrain_custom_config = $this->logDrainCustomConfig;
            $this->server->settings->logdrain_custom_config_parser = $this->logDrainCustomConfigParser;
        } else {
            $this->isLogDrainCustomEnabled = $this->server->settings->is_logdrain_custom_enabled;
            $this->logDrainCustomConfig = auth()->user()->can('update', $this->server)
                ? $this->server->settings->logdrain_custom_config
                : null;
            $this->logDrainCustomConfigParser = auth()->user()->can('update', $this->server)
                ? $this->server->settings->logdrain_custom_config_parser
                : null;
        }
    }

    private function syncDataCloudwatch(bool $toModel = false): void
    {
        if ($toModel) {
            $this->server->settings->is_logdrain_cloudwatch_enabled = $this->isLogDrainCloudwatchEnabled;
            $this->server->settings->logdrain_cloudwatch_region = $this->logDrainCloudwatchRegion;
            $this->server->settings->logdrain_cloudwatch_group = $this->logDrainCloudwatchGroup;
            $this->server->settings->logdrain_cloudwatch_stream_prefix = $this->logDrainCloudwatchStreamPrefix;
        } else {
            $this->isLogDrainCloudwatchEnabled = $this->server->settings->is_logdrain_cloudwatch_enabled;
            $this->logDrainCloudwatchRegion = $this->server->settings->logdrain_cloudwatch_region;
            $this->logDrainCloudwatchGroup = $this->server->settings->logdrain_cloudwatch_group;
            $this->logDrainCloudwatchStreamPrefix = $this->server->settings->logdrain_cloudwatch_stream_prefix;
        }
    }

    private function syncData(bool $toModel = false, ?string $type = null): void
    {
        if ($toModel) {
            $this->customValidation();
            if ($type === 'newrelic') {
                $this->syncDataNewRelic($toModel);
            } elseif ($type === 'axiom') {
                $this->syncDataAxiom($toModel);
            } elseif ($type === 'custom') {
                $this->syncDataCustom($toModel);
            } elseif ($type === 'cloudwatch') {
                $this->syncDataCloudwatch($toModel);
            } else {
                $this->syncDataNewRelic($toModel);
                $this->syncDataAxiom($toModel);
                $this->syncDataCustom($toModel);
                $this->syncDataCloudwatch($toModel);
            }
            if ($this->enabledLogDrainCount() > 0) {
                $this->server->settings->is_logdrain_highlight_enabled = false;
            }
            $this->auditLogDrain('updated');
            $this->server->settings->save();
        } else {
            if ($type === 'newrelic') {
                $this->syncDataNewRelic($toModel);
            } elseif ($type === 'axiom') {
                $this->syncDataAxiom($toModel);
            } elseif ($type === 'custom') {
                $this->syncDataCustom($toModel);
            } elseif ($type === 'cloudwatch') {
                $this->syncDataCloudwatch($toModel);
            } else {
                $this->syncDataNewRelic($toModel);
                $this->syncDataAxiom($toModel);
                $this->syncDataCustom($toModel);
                $this->syncDataCloudwatch($toModel);
            }
        }
    }

    public function customValidation()
    {
        if ($this->enabledLogDrainCount() > 1) {
            $this->syncData();

            throw ValidationException::withMessages([
                'logDrain' => 'Only one log drain can be enabled at a time. Disable the current log drain first.',
            ]);
        }

        if ($this->isLogDrainNewRelicEnabled) {
            try {
                $this->validate([
                    'logDrainNewRelicLicenseKey' => ['required', 'regex:/^[a-zA-Z0-9_\-\.]+$/'],
                    'logDrainNewRelicBaseUri' => ['required', 'url'],
                ]);
            } catch (\Throwable $e) {
                $this->isLogDrainNewRelicEnabled = false;

                throw $e;
            }
        } elseif ($this->isLogDrainAxiomEnabled) {
            try {
                $this->validate([
                    'logDrainAxiomDatasetName' => ['required', 'regex:/^[a-zA-Z0-9_\-\.]+$/'],
                    'logDrainAxiomApiKey' => ['required', 'regex:/^[a-zA-Z0-9_\-\.]+$/'],
                ]);
            } catch (\Throwable $e) {
                $this->isLogDrainAxiomEnabled = false;

                throw $e;
            }
        } elseif ($this->isLogDrainCustomEnabled) {
            try {
                $this->validate([
                    'logDrainCustomConfig' => ['required'],
                    'logDrainCustomConfigParser' => ['string', 'nullable'],
                ]);
            } catch (\Throwable $e) {
                $this->isLogDrainCustomEnabled = false;

                throw $e;
            }
        } elseif ($this->isLogDrainCloudwatchEnabled) {
            try {
                $this->validate([
                    'logDrainCloudwatchRegion' => ['required', 'max:64', 'regex:/^[a-z]{2}(-[a-z]+)+-\d+$/'],
                    'logDrainCloudwatchGroup' => ['required', 'regex:/^[\.\-_\/#A-Za-z0-9]{1,512}$/'],
                    'logDrainCloudwatchStreamPrefix' => ['string', 'nullable', 'max:256', 'regex:/^[A-Za-z0-9_\-\.\/#]*$/'],
                ]);
            } catch (\Throwable $e) {
                $this->isLogDrainCloudwatchEnabled = false;

                throw $e;
            }
        }
    }

    public function instantSave()
    {
        try {
            $this->authorize('update', $this->server);
            $this->syncData(true);
            $this->auditLogDrain('updated');
            if ($this->server->isLogDrainEnabled()) {
                StartLogDrain::run($this->server);
                $this->dispatch('success', 'Log drain service started.');
            } else {
                StopLogDrain::run($this->server);
                $this->dispatch('success', 'Log drain service stopped.');
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function toggleLogDrain(string $type): void
    {
        $previousNewRelicEnabled = $this->server->settings->is_logdrain_newrelic_enabled;
        $previousAxiomEnabled = $this->server->settings->is_logdrain_axiom_enabled;
        $previousCustomEnabled = $this->server->settings->is_logdrain_custom_enabled;
        $previousCloudwatchEnabled = $this->server->settings->is_logdrain_cloudwatch_enabled;
        $previousHighlightEnabled = $this->server->settings->is_logdrain_highlight_enabled;

        try {
            $this->authorize('update', $this->server);
            $this->resetErrorBag();

            $enabledProperty = $this->enabledProperty($type);

            if ($this->{$enabledProperty}) {
                $this->{$enabledProperty} = false;
            } else {
                $this->validateLogDrainSettings($type);
                $this->isLogDrainNewRelicEnabled = $type === 'newrelic';
                $this->isLogDrainAxiomEnabled = $type === 'axiom';
                $this->isLogDrainCustomEnabled = $type === 'custom';
                $this->isLogDrainCloudwatchEnabled = $type === 'cloudwatch';
            }

            $this->syncData(true);
            $this->auditLogDrain($this->{$enabledProperty} ? 'enabled' : 'disabled', $type);

            if ($this->server->isLogDrainEnabled()) {
                StartLogDrain::run($this->server);
                $this->dispatch('success', 'Log drain service started.');
            } else {
                StopLogDrain::run($this->server);
                $this->dispatch('success', 'Log drain service stopped.');
            }
        } catch (\Throwable $e) {
            // Restore the previously persisted enabled flags so the UI/DB never
            // claim a runtime state that the Start/StopLogDrain action failed to apply.
            $this->server->settings->is_logdrain_newrelic_enabled = $previousNewRelicEnabled;
            $this->server->settings->is_logdrain_axiom_enabled = $previousAxiomEnabled;
            $this->server->settings->is_logdrain_custom_enabled = $previousCustomEnabled;
            $this->server->settings->is_logdrain_cloudwatch_enabled = $previousCloudwatchEnabled;
            $this->server->settings->is_logdrain_highlight_enabled = $previousHighlightEnabled;
            $this->server->settings->save();
            $this->syncData();

            handleError($e, $this);
        }
    }

    public function submit()
    {
        try {
            $this->authorize('update', $this->server);
            $this->syncData(true);
            $this->dispatch('success', 'Settings saved.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.server.log-drains');
    }

    private function enabledLogDrainCount(): int
    {
        return count(array_filter([
            $this->isLogDrainNewRelicEnabled,
            $this->isLogDrainAxiomEnabled,
            $this->isLogDrainCustomEnabled,
            $this->isLogDrainCloudwatchEnabled,
        ]));
    }

    private function enabledProperty(string $type): string
    {
        return match ($type) {
            'newrelic' => 'isLogDrainNewRelicEnabled',
            'axiom' => 'isLogDrainAxiomEnabled',
            'custom' => 'isLogDrainCustomEnabled',
            'cloudwatch' => 'isLogDrainCloudwatchEnabled',
            default => throw new \InvalidArgumentException('Unknown log drain type.'),
        };
    }

    private function auditLogDrain(string $action, ?string $type = null): void
    {
        auditLog("ui.server.log_drain.{$action}", [
            'team_id' => $this->server->team_id,
            'server_uuid' => $this->server->uuid,
            'server_name' => $this->server->name,
            'provider' => $type,
        ]);
    }

    private function validateLogDrainSettings(string $type): void
    {
        match ($type) {
            'newrelic' => $this->validate([
                'logDrainNewRelicLicenseKey' => ['required', 'regex:/^[a-zA-Z0-9_\-\.]+$/'],
                'logDrainNewRelicBaseUri' => ['required', 'url'],
            ]),
            'axiom' => $this->validate([
                'logDrainAxiomDatasetName' => ['required', 'regex:/^[a-zA-Z0-9_\-\.]+$/'],
                'logDrainAxiomApiKey' => ['required', 'regex:/^[a-zA-Z0-9_\-\.]+$/'],
            ]),
            'custom' => $this->validate([
                'logDrainCustomConfig' => ['required'],
                'logDrainCustomConfigParser' => ['string', 'nullable'],
            ]),
            'cloudwatch' => $this->validate([
                'logDrainCloudwatchRegion' => ['required', 'max:64', 'regex:/^[a-z]{2}(-[a-z]+)+-\d+$/'],
                'logDrainCloudwatchGroup' => ['required', 'regex:/^[\.\-_\/#A-Za-z0-9]{1,512}$/'],
                'logDrainCloudwatchStreamPrefix' => ['string', 'nullable', 'max:256', 'regex:/^[A-Za-z0-9_\-\.\/#]*$/'],
            ]),
            default => throw new \InvalidArgumentException('Unknown log drain type.'),
        };
    }
}
