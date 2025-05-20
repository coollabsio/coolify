<?php

namespace App\Livewire\Server;

use App\Jobs\CollectServerInfoJob;
use App\Models\Server;
use Livewire\Component;

class Info extends Component
{
    public Server $server;
    public bool $isCollecting = false;

    public function getListeners()
    {
        $teamId = auth()->user()->currentTeam()->id;

        return [
            "echo-private:team.{$teamId},ServerInfoUpdated" => 'refresh',
            'refreshServerInfo' => 'refresh',
        ];
    }

    public function mount(string $server_uuid)
    {
        try {
            $this->server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();

            // If server info hasn't been collected yet, collect it automatically
            if (!$this->hasServerInfo()) {
                $this->collectServerInfo();
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function refresh()
    {
        // Refresh the component
        $this->server = Server::ownedByCurrentTeam()->whereUuid($this->server->uuid)->firstOrFail();

        // Reset the collecting flag
        $this->isCollecting = false;

        // Show a notification that the collection is complete
        $this->dispatch('notify', [
            'type' => 'success',
            'message' => 'Server information has been updated.',
        ]);
    }

    public function collectServerInfo()
    {
        if ($this->isCollecting) {
            return;
        }

        $this->isCollecting = true;

        // Dispatch the job to collect server information
        CollectServerInfoJob::dispatch($this->server);

        // Show a notification that the collection has started
        $this->dispatch('notify', [
            'type' => 'info',
            'message' => 'Server information collection has started. This may take a few moments.',
        ]);
    }

    private function hasServerInfo()
    {
        // Check if any server info has been collected
        return $this->server->settings->cpu_model ||
               $this->server->settings->memory_total ||
               $this->server->settings->disk_total ||
               $this->server->settings->os_name;
    }

    public function render()
    {
        return view('livewire.server.info');
    }
}
