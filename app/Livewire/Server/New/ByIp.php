<?php

namespace App\Livewire\Server\New;

use App\Enums\ProxyTypes;
use App\Models\Server;
use App\Models\Team;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ByIp extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public $private_keys;

    #[Locked]
    public $limit_reached;

    public ?int $private_key_id = null;

    public $new_private_key_name;

    public $new_private_key_description;

    public $new_private_key_value;

    public string $name;

    public ?string $description = null;

    public string $ip;

    public string $user = 'root';

    public int $port = 22;

    public bool $is_build_server = false;

    public function mount()
    {
        $this->name = generate_random_name();
        $this->private_key_id = $this->private_keys->first()?->id;
    }

    protected function rules(): array
    {
        return [
            'private_key_id' => 'nullable|integer',
            'new_private_key_name' => 'nullable|string',
            'new_private_key_description' => 'nullable|string',
            'new_private_key_value' => 'nullable|string',
            'name' => ValidationPatterns::nameRules(),
            'description' => ValidationPatterns::descriptionRules(),
            'ip' => 'required|string',
            'user' => 'required|string',
            'port' => 'required|integer|between:1,65535',
            'is_build_server' => 'required|boolean',
        ];
    }

    protected function messages(): array
    {
        return array_merge(ValidationPatterns::combinedMessages(), [
            'private_key_id.integer' => 'The Private Key field must be an integer.',
            'private_key_id.nullable' => 'The Private Key field is optional.',
            'new_private_key_name.string' => 'The Private Key Name must be a string.',
            'new_private_key_description.string' => 'The Private Key Description must be a string.',
            'new_private_key_value.string' => 'The Private Key Value must be a string.',
            'ip.required' => 'The IP Address/Domain is required.',
            'ip.string' => 'The IP Address/Domain must be a string.',
            'user.required' => 'The User field is required.',
            'user.string' => 'The User field must be a string.',
            'port.required' => 'The Port field is required.',
            'port.integer' => 'The Port field must be an integer.',
            'port.between' => 'The Port field must be between 1 and 65535.',
            'is_build_server.required' => 'The Build Server field is required.',
            'is_build_server.boolean' => 'The Build Server field must be true or false.',
        ]);
    }

    public function setPrivateKey(string $private_key_id)
    {
        $this->private_key_id = $private_key_id;
    }

    public function instantSave()
    {
        // $this->dispatch('success', 'Application settings updated!');
    }

    public function submit()
    {
        $this->validate();
        try {
            $this->authorize('create', Server::class);
            if (Server::where('team_id', currentTeam()->id)
                ->where('ip', $this->ip)
                ->exists()) {
                return $this->dispatch('error', 'This IP/Domain is already in use by another server in your team.');
            }

            if (is_null($this->private_key_id)) {
                return $this->dispatch('error', 'You must select a private key');
            }
            if (Team::serverLimitReached()) {
                return $this->dispatch('error', 'You have reached the server limit for your subscription.');
            }
            $payload = [
                'name' => $this->name,
                'description' => $this->description,
                'ip' => $this->ip,
                'user' => $this->user,
                'port' => $this->port,
                'team_id' => currentTeam()->id,
                'private_key_id' => $this->private_key_id,
            ];
            if ($this->is_build_server) {
                data_forget($payload, 'proxy');
            }
            $server = Server::create($payload);
            $server->proxy->set('status', 'exited');
            $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
            $server->save();
            $server->settings->is_build_server = $this->is_build_server;
            $server->settings->save();

            return redirect()->route('server.show', $server->uuid);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
