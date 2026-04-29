<?php

namespace App\Livewire\SharedVariables\Server;

use App\Models\Server;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class Show extends Component
{
    use AuthorizesRequests;

    public Server $server;

    public string $view = 'normal';

    public ?string $variables = null;

    protected $listeners = ['refreshEnvs' => 'refreshEnvs', 'saveKey' => 'saveKey', 'environmentVariableDeleted' => 'refreshEnvs'];

    public function saveKey($data)
    {
        try {
            $this->authorize('update', $this->server);

            if (in_array($data['key'], ['COOLIFY_SERVER_UUID', 'COOLIFY_SERVER_NAME'])) {
                throw new \Exception('Cannot create predefined variable.');
            }

            $found = $this->server->environment_variables()->where('key', $data['key'])->first();
            if ($found) {
                throw new \Exception('Variable already exists.');
            }
            $this->server->environment_variables()->create([
                'key' => $data['key'],
                'value' => $data['value'],
                'is_multiline' => $data['is_multiline'],
                'is_literal' => $data['is_literal'],
                'comment' => $data['comment'] ?? null,
                'type' => 'server',
                'team_id' => currentTeam()->id,
            ]);
            $this->server->refresh();
            $this->getDevView();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function mount(?string $server_uuid = null)
    {
        $serverUuid = $server_uuid ?? request()->route('server_uuid');
        $teamId = currentTeam()->id;
        $server = Server::where('team_id', $teamId)->where('uuid', $serverUuid)->first();
        if (! $server) {
            return redirect()->route('dashboard');
        }
        $this->authorize('view', $server);
        $this->server = $server;
        $this->getDevView();
    }

    public function switch()
    {
        $this->authorize('view', $this->server);
        $this->view = $this->view === 'normal' ? 'dev' : 'normal';
        $this->getDevView();
    }

    public function getDevView()
    {
        $this->variables = $this->formatEnvironmentVariables($this->server->environment_variables->whereNotIn('key', ['COOLIFY_SERVER_UUID', 'COOLIFY_SERVER_NAME'])->sortBy('key'));
    }

    private function formatEnvironmentVariables($variables)
    {
        return $variables->map(function ($item) {
            if ($item->is_shown_once) {
                return "$item->key=(Locked Secret, delete and add again to change)";
            }
            if ($item->is_multiline) {
                return "$item->key=(Multiline environment variable, edit in normal view)";
            }

            return "$item->key=$item->value";
        })->join("\n");
    }

    public function submit()
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

        $changesMade = DB::transaction(function () use ($variables) {
            // Delete removed variables
            $deletedCount = $this->deleteRemovedVariables($variables);

            // Update or create variables
            $updatedCount = $this->updateOrCreateVariables($variables);

            return $deletedCount > 0 || $updatedCount > 0;
        });

        if ($changesMade) {
            $this->dispatch('success', 'Environment variables updated.');
        }
    }

    private function deleteRemovedVariables($variables)
    {
        $variablesToDelete = $this->server->environment_variables()
            ->whereNotIn('key', array_keys($variables))
            ->whereNotIn('key', ['COOLIFY_SERVER_UUID', 'COOLIFY_SERVER_NAME'])
            ->get();

        if ($variablesToDelete->isEmpty()) {
            return 0;
        }

        $this->server->environment_variables()
            ->whereNotIn('key', array_keys($variables))
            ->whereNotIn('key', ['COOLIFY_SERVER_UUID', 'COOLIFY_SERVER_NAME'])
            ->delete();

        return $variablesToDelete->count();
    }

    private function updateOrCreateVariables($variables)
    {
        $count = 0;
        foreach ($variables as $key => $data) {
            $value = is_array($data) ? ($data['value'] ?? '') : $data;
            $comment = is_array($data) ? ($data['comment'] ?? null) : null;

            // Skip predefined variables
            if (in_array($key, ['COOLIFY_SERVER_UUID', 'COOLIFY_SERVER_NAME'])) {
                continue;
            }
            $found = $this->server->environment_variables()->where('key', $key)->first();

            if ($found) {
                if (! $found->is_shown_once && ! $found->is_multiline) {
                    if ($found->value !== $value || $found->comment !== $comment) {
                        $found->value = $value;
                        $found->comment = $comment;
                        $found->save();
                        $count++;
                    }
                }
            } else {
                $this->server->environment_variables()->create([
                    'key' => $key,
                    'value' => $value,
                    'comment' => $comment,
                    'is_multiline' => false,
                    'is_literal' => false,
                    'type' => 'server',
                    'team_id' => currentTeam()->id,
                ]);
                $count++;
            }
        }

        return $count;
    }

    public function refreshEnvs()
    {
        $this->server->refresh();
        $this->getDevView();
    }

    public function render()
    {
        return view('livewire.shared-variables.server.show');
    }
}
