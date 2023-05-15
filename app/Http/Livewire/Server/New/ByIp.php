<?php

namespace App\Http\Livewire\Server\New;

use App\Models\PrivateKey;
use App\Models\Server;
use Livewire\Component;

class ByIp extends Component
{
    public $private_keys;
    public int|null $private_key_id = null;
    public $new_private_key_name;
    public $new_private_key_description;
    public $new_private_key_value;

    public string $name;
    public string|null $description = null;
    public string $ip;
    public string $user = 'root';
    public int $port = 22;

    protected $rules = [
        'name' => 'required',
        'ip' => 'required',
        'user' => 'required',
        'port' => 'required|integer',
    ];
    public function mount()
    {
        $this->name = generateRandomName();
    }
    public function setPrivateKey(string $private_key_id)
    {
        $this->private_key_id = $private_key_id;
    }
    public function submit()
    {
        try {
            if (!$this->private_key_id) {
                return $this->emit('error', 'You must select a private key');
            }
            $this->validate();
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
        } catch (\Exception $e) {
            return generalErrorHandler($e);
        }
    }
}
