<?php

namespace App\Livewire\Server\Proxy;

use App\Models\Server;
use Livewire\Component;
use Symfony\Component\Yaml\Yaml;

class NewDynamicConfiguration extends Component
{
    public string $fileName = '';

    public string $value = '';

    public bool $newFile = false;

    public Server $server;

    public $server_id;

    public $parameters = [];

    public function mount()
    {
        $this->parameters = get_route_parameters();
        if ($this->fileName !== '') {
            $this->fileName = str_replace('|', '.', $this->fileName);
        }
    }

    public function addDynamicConfiguration()
    {
        try {
            $this->validate([
                'fileName' => 'required',
                'value' => 'required',
            ]);
            if (data_get($this->parameters, 'server_uuid')) {
                $this->server = Server::ownedByCurrentTeam()->whereUuid(data_get($this->parameters, 'server_uuid'))->first();
            }
            if (! is_null($this->server_id)) {
                $this->server = Server::ownedByCurrentTeam()->whereId($this->server_id)->first();
            }
            if (is_null($this->server)) {
                return redirect()->route('server.index');
            }
            $proxy_type = $this->server->proxyType();
            if ($proxy_type === 'TRAEFIK_V2') {
                if (! str($this->fileName)->endsWith('.yaml') && ! str($this->fileName)->endsWith('.yml')) {
                    $this->fileName = "{$this->fileName}.yaml";
                }
                if ($this->fileName === 'coolify.yaml') {
                    $this->dispatch('error', 'File name is reserved.');

                    return;
                }
            } elseif ($proxy_type === 'CADDY') {
                if (! str($this->fileName)->endsWith('.caddy')) {
                    $this->fileName = "{$this->fileName}.caddy";
                }
            }
            $proxy_path = $this->server->proxyPath();
            $file = "{$proxy_path}/dynamic/{$this->fileName}";
            if ($this->newFile) {
                $exists = instant_remote_process(["test -f $file && echo 1 || echo 0"], $this->server);
                if ($exists == 1) {
                    $this->dispatch('error', 'File already exists');

                    return;
                }
            }
            if ($proxy_type === 'TRAEFIK_V2') {
                $yaml = Yaml::parse($this->value);
                $yaml = Yaml::dump($yaml, 10, 2);
                $this->value = $yaml;
            }
            $base64_value = base64_encode($this->value);
            instant_remote_process([
                "echo '{$base64_value}' | base64 -d | tee {$file} > /dev/null",
            ], $this->server);
            if ($proxy_type === 'CADDY') {
                $this->server->reloadCaddy();
            }
            $this->dispatch('loadDynamicConfigurations');
            $this->dispatch('dynamic-configuration-added');
            $this->dispatch('success', 'Dynamic configuration saved.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.server.proxy.new-dynamic-configuration');
    }
}
