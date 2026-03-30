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

    protected $listeners = ['refreshEnvs' => 'refreshEnvs', 'saveKey', 'environmentVariableDeleted' => 'refreshEnvs'];

    public function saveKey($data): void
    {
        try {
            $this->authorize('update', $this->server);

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

    public function mount(): void
    {
        $this->server = Server::ownedByCurrentTeam()->where('uuid', request()->route('server_uuid'))->firstOrFail();
        $this->getDevView();
    }

    public function switch(): void
    {
        $this->authorize('view', $this->server);
        $this->view = $this->view === 'normal' ? 'dev' : 'normal';
        $this->getDevView();
    }

    public function getDevView(): void
    {
        $this->variables = $this->formatEnvironmentVariables($this->server->environment_variables->sortBy('key'));
    }

    private function formatEnvironmentVariables($variables): string
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

    public function submit(): void
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

    private function handleBulkSubmit(): void
    {
        $variables = parseEnvFormatToArray($this->variables);
        $changesMade = false;

        DB::transaction(function () use ($variables, &$changesMade) {
            $deletedCount = $this->deleteRemovedVariables($variables);
            if ($deletedCount > 0) {
                $changesMade = true;
            }

            $updatedCount = $this->updateOrCreateVariables($variables);
            if ($updatedCount > 0) {
                $changesMade = true;
            }
        });

        if ($changesMade) {
            $this->dispatch('success', 'Environment variables updated.');
        }
    }

    private function deleteRemovedVariables($variables): int
    {
        $variablesToDelete = $this->server->environment_variables()->whereNotIn('key', array_keys($variables))->get();

        if ($variablesToDelete->isEmpty()) {
            return 0;
        }

        $this->server->environment_variables()->whereNotIn('key', array_keys($variables))->delete();

        return $variablesToDelete->count();
    }

    private function updateOrCreateVariables($variables): int
    {
        $count = 0;
        foreach ($variables as $key => $data) {
            $value = is_array($data) ? ($data['value'] ?? '') : $data;

            $found = $this->server->environment_variables()->where('key', $key)->first();

            if ($found) {
                if (! $found->is_shown_once && ! $found->is_multiline) {
                    if ($found->value !== $value) {
                        $found->value = $value;
                        $found->save();
                        $count++;
                    }
                }
            } else {
                $this->server->environment_variables()->create([
                    'key' => $key,
                    'value' => $value,
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

    public function refreshEnvs(): void
    {
        $this->server->refresh();
        $this->getDevView();
    }

    public function render()
    {
        return view('livewire.shared-variables.server.show');
    }
}
