<?php

namespace App\Livewire\Server;

use App\Actions\Server\StartLogDrain;
use App\Actions\Server\StopLogDrain;
use App\Models\Server;
use Livewire\Attributes\Validate;
use Livewire\Component;

class LogDrains extends Component
{
    public Server $server;

    #[Validate(['boolean'])]
    public bool $isLogDrainNewRelicEnabled = false;

    #[Validate(['boolean'])]
    public bool $isLogDrainCustomEnabled = false;

    #[Validate(['boolean'])]
    public bool $isLogDrainAxiomEnabled = false;

    #[Validate(['string', 'nullable'])]
    public ?string $logDrainNewRelicLicenseKey = null;

    #[Validate(['url', 'nullable'])]
    public ?string $logDrainNewRelicBaseUri = null;

    #[Validate(['string', 'nullable'])]
    public ?string $logDrainAxiomDatasetName = null;

    #[Validate(['string', 'nullable'])]
    public ?string $logDrainAxiomApiKey = null;

    #[Validate(['string', 'nullable'])]
    public ?string $logDrainCustomConfig = null;

    #[Validate(['string', 'nullable'])]
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

    public function syncDataNewRelic(bool $toModel = false)
    {
        if ($toModel) {
            $this->server->settings->is_logdrain_newrelic_enabled = $this->isLogDrainNewRelicEnabled;
            $this->server->settings->logdrain_newrelic_license_key = $this->logDrainNewRelicLicenseKey;
            $this->server->settings->logdrain_newrelic_base_uri = $this->logDrainNewRelicBaseUri;
        } else {
            $this->isLogDrainNewRelicEnabled = $this->server->settings->is_logdrain_newrelic_enabled;
            $this->logDrainNewRelicLicenseKey = $this->server->settings->logdrain_newrelic_license_key;
            $this->logDrainNewRelicBaseUri = $this->server->settings->logdrain_newrelic_base_uri;
        }
    }

    public function syncDataAxiom(bool $toModel = false)
    {
        if ($toModel) {
            $this->server->settings->is_logdrain_axiom_enabled = $this->isLogDrainAxiomEnabled;
            $this->server->settings->logdrain_axiom_dataset_name = $this->logDrainAxiomDatasetName;
            $this->server->settings->logdrain_axiom_api_key = $this->logDrainAxiomApiKey;
        } else {
            $this->isLogDrainAxiomEnabled = $this->server->settings->is_logdrain_axiom_enabled;
            $this->logDrainAxiomDatasetName = $this->server->settings->logdrain_axiom_dataset_name;
            $this->logDrainAxiomApiKey = $this->server->settings->logdrain_axiom_api_key;
        }
    }

    public function syncDataCustom(bool $toModel = false)
    {
        if ($toModel) {
            $this->server->settings->is_logdrain_custom_enabled = $this->isLogDrainCustomEnabled;
            $this->server->settings->logdrain_custom_config = $this->logDrainCustomConfig;
            $this->server->settings->logdrain_custom_config_parser = $this->logDrainCustomConfigParser;
        } else {
            $this->isLogDrainCustomEnabled = $this->server->settings->is_logdrain_custom_enabled;
            $this->logDrainCustomConfig = $this->server->settings->logdrain_custom_config;
            $this->logDrainCustomConfigParser = $this->server->settings->logdrain_custom_config_parser;
        }
    }

    public function syncData(bool $toModel = false, ?string $type = null)
    {
        if ($toModel) {
            $this->customValidation();
            if ($type === 'newrelic') {
                $this->syncDataNewRelic($toModel);
            } elseif ($type === 'axiom') {
                $this->syncDataAxiom($toModel);
            } elseif ($type === 'custom') {
                $this->syncDataCustom($toModel);
            } else {
                $this->syncDataNewRelic($toModel);
                $this->syncDataAxiom($toModel);
                $this->syncDataCustom($toModel);
            }
            $this->server->settings->save();
        } else {
            if ($type === 'newrelic') {
                $this->syncDataNewRelic($toModel);
            } elseif ($type === 'axiom') {
                $this->syncDataAxiom($toModel);
            } elseif ($type === 'custom') {
                $this->syncDataCustom($toModel);
            } else {
                $this->syncDataNewRelic($toModel);
                $this->syncDataAxiom($toModel);
                $this->syncDataCustom($toModel);
            }
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
            $this->syncData(true, $type);
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
