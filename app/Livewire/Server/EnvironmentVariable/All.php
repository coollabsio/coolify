<?php

namespace App\Livewire\Server\EnvironmentVariable;

use App\Models\Server;
use App\Models\ServerEnvironmentVariable;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class All extends Component
{
    use AuthorizesRequests;

    public Server $server;

    public string $view = 'normal';

    public ?string $variables = null;

    protected $listeners = [
        'saveKey' => 'submit',
        'refreshEnvs',
        'environmentVariableDeleted' => 'refreshEnvs',
    ];

    public function mount(string $server_uuid)
    {
        $this->server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        $this->getDevView();
    }

    public function getEnvironmentVariablesProperty()
    {
        return $this->server->environmentVariables()->orderBy('key')->get();
    }

    public function getDevView()
    {
        $this->variables = $this->environmentVariables->map(function ($item) {
            if ($item->is_shown_once) {
                return "$item->key=(Locked Secret, delete and add again to change)";
            }
            if ($item->is_multiline) {
                return "$item->key=(Multiline environment variable, edit in normal view)";
            }

            return "$item->key=$item->value";
        })->join("\n");
    }

    public function switch()
    {
        $this->view = $this->view === 'normal' ? 'dev' : 'normal';
        $this->getDevView();
    }

    public function submit($data = null)
    {
        try {
            $this->authorize('update', $this->server);
            if ($data === null) {
                $this->handleBulkSubmit();
            } else {
                $this->handleSingleSubmit($data);
            }
            $this->getDevView();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        } finally {
            $this->refreshEnvs();
        }
    }

    private function handleBulkSubmit()
    {
        $variables = parseEnvFormatToArray($this->variables);
        $changesMade = false;

        // Delete removed variables
        $variablesToDelete = $this->server->environmentVariables()
            ->whereNotIn('key', array_keys($variables))
            ->get();

        if ($variablesToDelete->isNotEmpty()) {
            $this->server->environmentVariables()
                ->whereNotIn('key', array_keys($variables))
                ->delete();
            $changesMade = true;
        }

        // Update or create variables
        foreach ($variables as $key => $value) {
            $found = $this->server->environmentVariables()->where('key', $key)->first();
            if ($found) {
                if (! $found->is_shown_once && ! $found->is_multiline) {
                    if ($found->value !== $value) {
                        $found->value = $value;
                        $found->save();
                        $changesMade = true;
                    }
                }
            } else {
                ServerEnvironmentVariable::create([
                    'key' => $key,
                    'value' => $value,
                    'server_id' => $this->server->id,
                ]);
                $changesMade = true;
            }
        }

        if ($changesMade) {
            $this->dispatch('success', 'Environment variables updated.');
        }
    }

    private function handleSingleSubmit($data)
    {
        $found = $this->server->environmentVariables()->where('key', $data['key'])->first();
        if ($found) {
            $this->dispatch('error', 'Environment variable already exists.');

            return;
        }

        ServerEnvironmentVariable::create([
            'key' => $data['key'],
            'value' => $data['value'],
            'is_multiline' => $data['is_multiline'] ?? false,
            'is_literal' => $data['is_literal'] ?? false,
            'is_shown_once' => $data['is_shown_once'] ?? false,
            'server_id' => $this->server->id,
        ]);

        $this->dispatch('success', 'Environment variable added.');
    }

    public function refreshEnvs()
    {
        $this->server->refresh();
        unset($this->environmentVariables);
        $this->getDevView();
    }

    public function render()
    {
        return view('livewire.server.environment-variable.all');
    }
}
