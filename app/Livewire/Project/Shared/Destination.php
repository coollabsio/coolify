<?php

namespace App\Livewire\Project\Shared;

use App\Models\Server;
use Livewire\Component;

class Destination extends Component
{
    public $resource;
    public $servers = [];
    public $networks = [];

    public function mount()
    {
        $this->loadData();
    }
    public function loadData()
    {
        $all_networks = collect([]);
        $all_networks = $all_networks->push($this->resource->destination);
        $all_networks = $all_networks->merge($this->resource->additional_networks);

        $this->networks = Server::isUsable()->get()->map(function ($server) {
            return $server->standaloneDockers;
        })->flatten();
        $this->networks = $this->networks->reject(function ($network) use ($all_networks) {
            return $all_networks->pluck('id')->contains($network->id);
        });
    }
    public function addServer(int $network_id, int $server_id)
    {
        $this->resource->additional_networks()->attach($network_id, ['server_id' => $server_id]);
        $this->resource->load(['additional_networks']);
        $this->loadData();

    }
    public function removeServer(int $network_id, int $server_id)
    {
        $this->resource->additional_networks()->detach($network_id, ['server_id' => $server_id]);
        $this->resource->load(['additional_networks']);
        $this->loadData();
    }
}
