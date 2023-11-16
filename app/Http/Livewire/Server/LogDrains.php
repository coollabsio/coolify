<?php

namespace App\Http\Livewire\Server;

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
    ];

    public function mount() {
        $this->parameters = get_route_parameters();
        try {
            $this->server = Server::ownedByCurrentTeam(['name', 'description', 'ip', 'port', 'user', 'proxy'])->whereUuid(request()->server_uuid)->first();
            if (is_null($this->server)) {
                return redirect()->route('server.all');
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
    public function configureLogDrain(string $type) {
        try {
            $this->server->logDrain($type);
            $this->emit('serverRefresh');
            $this->emit('success', 'Log drain configured successfully.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
    public function instantSave(string $type) {
        $this->submit($type);
    }
    public function submit(string $type) {
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
                ]);
            } else if ($type === 'highlight') {
                $this->validate([
                    'server.settings.is_logdrain_highlight_enabled' => 'required|boolean',
                    'server.settings.logdrain_highlight_project_id' => 'required|string',
                ]);
                $this->server->settings->update([
                    'is_logdrain_newrelic_enabled' => false,
                ]);
            }
            $this->server->settings->save();
            $this->emit('success', 'Settings saved successfully.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
    public function render()
    {
        return view('livewire.server.log-drains');
    }
}
