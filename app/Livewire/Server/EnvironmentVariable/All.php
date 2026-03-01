<?php

namespace App\Livewire\Server\EnvironmentVariable;

use App\Models\Server;
use App\Models\ServerEnvironmentVariable;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Component;

class All extends Component
{
    use AuthorizesRequests;

    public Server $server;

    public string $view = 'normal';

    public ?string $variables = null;

    public string $newKey = '';

    public string $newValue = '';

    public bool $newIsMultiline = false;

    public bool $newIsLiteral = false;

    public bool $newIsBuildtime = false;

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

    public function addVariable()
    {
        try {
            $this->authorize('update', $this->server);

            $this->validate([
                'newKey' => 'required|string',
                'newValue' => 'required|string',
            ]);

            $found = $this->server->environmentVariables()->where('key', $this->newKey)->first();
            if ($found) {
                $this->dispatch('error', 'Environment variable already exists.');

                return;
            }

            $env = new ServerEnvironmentVariable;
            $env->key = $this->newKey;
            $env->value = $this->newValue;
            $env->is_multiline = $this->newIsMultiline;
            $env->is_literal = $this->newIsLiteral;
            $env->is_buildtime = $this->newIsBuildtime;
            $env->server_id = $this->server->id;
            $env->save();

            $this->newKey = '';
            $this->newValue = '';
            $this->newIsMultiline = false;
            $this->newIsLiteral = false;
            $this->newIsBuildtime = false;

            $this->dispatch('success', 'Environment variable added.');
            $this->refreshEnvs();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function submit($data = null)
    {
        try {
            $this->authorize('update', $this->server);

            $this->handleBulkSubmit();

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
        $deleted = $this->server->environmentVariables()->whereNotIn('key', array_keys($variables))->delete();
        if ($deleted > 0) {
            $changesMade = true;
        }

        // Update or create variables
        foreach ($variables as $key => $value) {
            $found = $this->server->environmentVariables()->where('key', $key)->first();
            if ($found) {
                if (! $found->is_shown_once && ! $found->is_multiline && $found->value !== $value) {
                    $found->value = $value;
                    $found->save();
                    $changesMade = true;
                }
            } else {
                $env = new ServerEnvironmentVariable;
                $env->key = $key;
                $env->value = $value;
                $env->server_id = $this->server->id;
                $env->save();
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

        $env = new ServerEnvironmentVariable;
        $env->key = $data['key'];
        $env->value = $data['value'];
        $env->is_multiline = $data['is_multiline'] ?? false;
        $env->is_literal = $data['is_literal'] ?? false;
        $env->is_buildtime = $data['is_buildtime'] ?? false;
        $env->is_shown_once = $data['is_shown_once'] ?? false;
        $env->server_id = $this->server->id;
        $env->save();

        $this->dispatch('success', 'Environment variable added.');
    }

    public function deleteEnvironmentVariable($uuid)
    {
        try {
            $this->authorize('update', $this->server);
            $env = $this->server->environmentVariables()->where('uuid', $uuid)->firstOrFail();
            $env->delete();
            $this->dispatch('success', 'Environment variable deleted.');
            $this->refreshEnvs();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
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
