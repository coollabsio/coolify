<?php

namespace App\Livewire\Server;

use App\Actions\Server\InstallLogDrain;
use App\Models\Server;
use Livewire\Component;

class LogDrains extends Component
{
    public Server $server;

    public $parameters = [];

    protected $rules = [
        'server.settings.is_logdrain_newrelic_enabled' => 'required|boolean',
        'server.settings.logdrain_newrelic_license_key' => 'required|string',
        'server.settings.logdrain_newrelic_base_uri' => 'required|string',
        'server.settings.is_logdrain_highlight_enabled' => 'required|boolean',
        'server.settings.logdrain_highlight_project_id' => 'required|string',
        'server.settings.is_logdrain_axiom_enabled' => 'required|boolean',
        'server.settings.logdrain_axiom_dataset_name' => 'required|string',
        'server.settings.logdrain_axiom_api_key' => 'required|string',
        'server.settings.is_logdrain_custom_enabled' => 'required|boolean',
        'server.settings.logdrain_custom_config' => 'required|string',
        'server.settings.logdrain_custom_config_parser' => 'nullable',
    ];

    protected $validationAttributes = [
        'server.settings.is_logdrain_newrelic_enabled' => 'New Relic log drain',
        'server.settings.logdrain_newrelic_license_key' => 'New Relic license key',
        'server.settings.logdrain_newrelic_base_uri' => 'New Relic base URI',
        'server.settings.is_logdrain_highlight_enabled' => 'Highlight log drain',
        'server.settings.logdrain_highlight_project_id' => 'Highlight project ID',
        'server.settings.is_logdrain_axiom_enabled' => 'Axiom log drain',
        'server.settings.logdrain_axiom_dataset_name' => 'Axiom dataset name',
        'server.settings.logdrain_axiom_api_key' => 'Axiom API key',
        'server.settings.is_logdrain_custom_enabled' => 'Custom log drain',
        'server.settings.logdrain_custom_config' => 'Custom log drain configuration',
        'server.settings.logdrain_custom_config_parser' => 'Custom log drain configuration parser',
    ];

    public function mount()
    {
        $this->parameters = get_route_parameters();
        try {
            $server = Server::ownedByCurrentTeam()->whereUuid(request()->server_uuid)->first();
            if (is_null($server)) {
                return redirect()->route('server.index');
            }
            $this->server = $server;
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function configureLogDrain()
    {
        try {
            InstallLogDrain::run($this->server);
            if (! $this->server->isLogDrainEnabled()) {
                $this->dispatch('serverRefresh');
                $this->dispatch('success', 'Log drain service stopped.');

                return;
            }
            $this->dispatch('serverRefresh');
            $this->dispatch('success', 'Log drain service started.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function instantSave(string $type)
    {
        try {
            $ok = $this->submit($type);
            if (! $ok) {
                return;
            }
            $this->configureLogDrain();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function submit(string $type)
    {
        try {
            $this->resetErrorBag();
            if ($type === 'newrelic') {
                $this->validate([
                    'server.settings.is_logdrain_newrelic_enabled' => 'required|boolean',
                    'server.settings.logdrain_newrelic_license_key' => 'required|string',
                    'server.settings.logdrain_newrelic_base_uri' => 'required|string',
                ]);
                $this->server->settings->update([
                    'is_logdrain_highlight_enabled' => false,
                    'is_logdrain_axiom_enabled' => false,
                    'is_logdrain_custom_enabled' => false,
                ]);
            } elseif ($type === 'highlight') {
                $this->validate([
                    'server.settings.is_logdrain_highlight_enabled' => 'required|boolean',
                    'server.settings.logdrain_highlight_project_id' => 'required|string',
                ]);
                $this->server->settings->update([
                    'is_logdrain_newrelic_enabled' => false,
                    'is_logdrain_axiom_enabled' => false,
                    'is_logdrain_custom_enabled' => false,
                ]);
            } elseif ($type === 'axiom') {
                $this->validate([
                    'server.settings.is_logdrain_axiom_enabled' => 'required|boolean',
                    'server.settings.logdrain_axiom_dataset_name' => 'required|string',
                    'server.settings.logdrain_axiom_api_key' => 'required|string',
                ]);
                $this->server->settings->update([
                    'is_logdrain_newrelic_enabled' => false,
                    'is_logdrain_highlight_enabled' => false,
                    'is_logdrain_custom_enabled' => false,
                ]);
            } elseif ($type === 'custom') {
                $this->validate([
                    'server.settings.is_logdrain_custom_enabled' => 'required|boolean',
                    'server.settings.logdrain_custom_config' => 'required|string',
                    'server.settings.logdrain_custom_config_parser' => 'nullable',
                ]);
                $this->server->settings->update([
                    'is_logdrain_newrelic_enabled' => false,
                    'is_logdrain_highlight_enabled' => false,
                    'is_logdrain_axiom_enabled' => false,
                ]);
            }
            $this->server->settings->save();
            $this->dispatch('success', 'Settings saved.');

            return true;
        } catch (\Throwable $e) {
            if ($type === 'newrelic') {
                $this->server->settings->update([
                    'is_logdrain_newrelic_enabled' => false,
                ]);
            } elseif ($type === 'highlight') {
                $this->server->settings->update([
                    'is_logdrain_highlight_enabled' => false,
                ]);
            } elseif ($type === 'axiom') {
                $this->server->settings->update([
                    'is_logdrain_axiom_enabled' => false,
                ]);
            } elseif ($type === 'custom') {
                $this->server->settings->update([
                    'is_logdrain_custom_enabled' => false,
                ]);
            }
            handleError($e, $this);

            return false;
        }
    }

    public function render()
    {
        return view('livewire.server.log-drains');
    }
}
