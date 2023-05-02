<?php

namespace App\Http\Livewire\Server\New;

use App\Models\PrivateKey;
use App\Models\Server;
use Livewire\Component;

class ByIp extends Component
{
    public $private_keys;
    public int $private_key_id;
    public $new_private_key_name;
    public $new_private_key_description;
    public $new_private_key_value;

    public string $name;
    public string $description;
    public string $ip;
    public string $user = 'root';
    public int $port = 22;

    public function mount()
    {
        $this->name =  generateRandomName();
        $this->private_keys = PrivateKey::where('team_id', session('currentTeam')->id)->get();
    }
    public function setPrivateKey($private_key_id)
    {
        $this->private_key_id = $private_key_id;
    }
    public function addPrivateKey()
    {
        $this->new_private_key_value = trim($this->new_private_key_value);
        if (!str_ends_with($this->new_private_key_value, "\n")) {
            $this->new_private_key_value .= "\n";
        }
        PrivateKey::create([
            'name' => $this->new_private_key_name,
            'description' => $this->new_private_key_description,
            'private_key' => $this->new_private_key_value,
            'team_id' => session('currentTeam')->id
        ]);
        session('currentTeam')->privateKeys = $this->private_keys = PrivateKey::where('team_id', session('currentTeam')->id)->get();
    }
    public function submit()
    {
        $server = Server::create([
            'name' => $this->name,
            'description' => $this->description,
            'ip' => $this->ip,
            'user' => $this->user,
            'port' => $this->port,
            'team_id' => session('currentTeam')->id,
            'private_key_id' => $this->private_key_id
        ]);
        return redirect()->route('server.show', $server->uuid);
    }
}
