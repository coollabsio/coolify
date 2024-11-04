<?php

namespace App\Livewire\Server;

use App\Actions\Server\StartLogDrain;
use App\Actions\Server\StopLogDrain;
use App\Models\Server;
use Livewire\Attributes\Rule;
use Livewire\Component;

class LogDrains extends Component
{
    public Server $server;

    #[Rule(['boolean'])]
    public bool $isLogDrainNewRelicEnabled = false;

    #[Rule(['boolean'])]
    public bool $isLogDrainCustomEnabled = false;

    #[Rule(['boolean'])]
    public bool $isLogDrainAxiomEnabled = false;

    #[Rule(['string', 'nullable'])]
    public ?string $logDrainNewRelicLicenseKey = null;

    #[Rule(['url', 'nullable'])]
    public ?string $logDrainNewRelicBaseUri = null;

    #[Rule(['string', 'nullable'])]
    public ?string $logDrainAxiomDatasetName = null;

    #[Rule(['string', 'nullable'])]
    public ?string $logDrainAxiomApiKey = null;

    #[Rule(['string', 'nullable'])]
    public ?string $logDrainCustomConfig = null;

    #[Rule(['string', 'nullable'])]
    public ?string $logDrainCustomConfigParser = null;

    public function mount(string $server_uuid)
    {
        try {
            $this->server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
            $this->syncData();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function syncData(bool $toModel = false)
    {
        if ($toModel) {
            $this->customValidation();
            $this->server->settings->is_logdrain_newrelic_enabled = $this->isLogDrainNewRelicEnabled;
            $this->server->settings->is_logdrain_axiom_enabled = $this->isLogDrainAxiomEnabled;
            $this->server->settings->is_logdrain_custom_enabled = $this->isLogDrainCustomEnabled;

            $this->server->settings->logdrain_newrelic_license_key = $this->logDrainNewRelicLicenseKey;
            $this->server->settings->logdrain_newrelic_base_uri = $this->logDrainNewRelicBaseUri;
            $this->server->settings->logdrain_axiom_dataset_name = $this->logDrainAxiomDatasetName;
            $this->server->settings->logdrain_axiom_api_key = $this->logDrainAxiomApiKey;
            $this->server->settings->logdrain_custom_config = $this->logDrainCustomConfig;
            $this->server->settings->logdrain_custom_config_parser = $this->logDrainCustomConfigParser;

            $this->server->settings->save();
        } else {
            $this->isLogDrainNewRelicEnabled = $this->server->settings->is_logdrain_newrelic_enabled;
            $this->isLogDrainAxiomEnabled = $this->server->settings->is_logdrain_axiom_enabled;
            $this->isLogDrainCustomEnabled = $this->server->settings->is_logdrain_custom_enabled;

            $this->logDrainNewRelicLicenseKey = $this->server->settings->logdrain_newrelic_license_key;
            $this->logDrainNewRelicBaseUri = $this->server->settings->logdrain_newrelic_base_uri;
            $this->logDrainAxiomDatasetName = $this->server->settings->logdrain_axiom_dataset_name;
            $this->logDrainAxiomApiKey = $this->server->settings->logdrain_axiom_api_key;
            $this->logDrainCustomConfig = $this->server->settings->logdrain_custom_config;
            $this->logDrainCustomConfigParser = $this->server->settings->logdrain_custom_config_parser;
        }
    }

    public function customValidation()
    {
        if ($this->isLogDrainNewRelicEnabled) {
            try {
                $this->validate([
                    'logDrainNewRelicLicenseKey' => ['required'],
                    'logDrainNewRelicBaseUri' => ['required', 'url'],
                ]);
            } catch (\Throwable $e) {
                $this->isLogDrainNewRelicEnabled = false;

                throw $e;
            }
        } elseif ($this->isLogDrainAxiomEnabled) {
            try {
                $this->validate([
                    'logDrainAxiomDatasetName' => ['required'],
                    'logDrainAxiomApiKey' => ['required'],
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
        }
    }

    public function instantSave()
    {
        try {
            $this->syncData(true);
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

    public function submit(string $type)
    {
        try {
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
}
