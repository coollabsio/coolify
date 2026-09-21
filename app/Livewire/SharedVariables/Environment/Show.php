<?php

namespace App\Livewire\SharedVariables\Environment;

use App\Exceptions\InfisicalManagedVariableException;
use App\Models\Application;
use App\Models\Project;
use App\Models\SharedEnvironmentVariable;
use App\Services\Infisical\InfisicalLock;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class Show extends Component
{
    use AuthorizesRequests;

    public Project $project;

    public Application $application;

    public $environment;

    public array $parameters;

    public string $view = 'normal';

    public ?string $variables = null;

    protected $listeners = ['refreshEnvs' => 'refreshEnvs', 'saveKey', 'environmentVariableDeleted' => 'refreshEnvs'];

    public function saveKey($data)
    {
        try {
            $this->authorize('update', $this->environment);

            $found = $this->environment->environment_variables()->where('key', $data['key'])->first();
            if ($found) {
                throw new \Exception('Variable already exists.');
            }
            $this->environment->environment_variables()->create([
                'key' => $data['key'],
                'value' => $data['value'],
                'is_multiline' => $data['is_multiline'],
                'is_literal' => $data['is_literal'],
                'comment' => $data['comment'] ?? null,
                'type' => 'environment',
                'team_id' => currentTeam()->id,
            ]);
            $this->environment->refresh();
            $this->getDevView();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function mount(?string $project_uuid = null, ?string $environment_uuid = null)
    {
        $this->parameters = get_route_parameters();
        $projectUuid = $project_uuid ?? request()->route('project_uuid');
        $environmentUuid = $environment_uuid ?? request()->route('environment_uuid');

        $this->project = Project::ownedByCurrentTeam()->where('uuid', $projectUuid)->firstOrFail();
        $this->environment = $this->project->environments()->where('uuid', $environmentUuid)->firstOrFail();
        $this->getDevView();
    }

    public function switch()
    {
        $this->authorize('view', $this->environment);
        $this->view = $this->view === 'normal' ? 'dev' : 'normal';
        $this->getDevView();
    }

    public function getDevView()
    {
        $this->variables = $this->formatEnvironmentVariables($this->editableVariables->sortBy('key'));
    }

    /**
     * User-owned rows: the only ones this screen may edit or delete.
     *
     * Rows flagged is_infisical_managed are owned by Infisical and are refreshed
     * by the next sync, so they are excluded from both
     * the normal-view table and the developer-view textarea.
     *
     * @return Collection<int, SharedEnvironmentVariable>
     */
    public function getEditableVariablesProperty(): Collection
    {
        return $this->environment->environment_variables->where('is_infisical_managed', false)->values();
    }

    /**
     * Infisical-owned rows for this environment, rendered read-only with a badge.
     *
     * @return Collection<int, SharedEnvironmentVariable>
     */
    public function getInheritedVariablesProperty(): Collection
    {
        return $this->environment->environment_variables->where('is_infisical_managed', true)->sortBy('key')->values();
    }

    private function formatEnvironmentVariables($variables)
    {
        $isMember = auth()->user()?->isMember();

        return $variables->map(function ($item) use ($isMember) {
            if ($isMember) {
                return "$item->key=(Hidden, only admins can view)";
            }
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
            $this->authorize('update', $this->environment);
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
        // The deletes below go through the relation query builder, which fires
        // no model events, so the deleting hook on the model never sees them. Without
        // this check a locked team's variables can still be removed by deleting
        // lines from the textarea and submitting.
        if (InfisicalLock::armedForTeam(currentTeam()?->id)) {
            throw InfisicalManagedVariableException::forBulkEdit();
        }

        $variables = parseEnvFormatToArray($this->variables);
        $changesMade = false;

        DB::transaction(function () use ($variables, &$changesMade) {
            // Delete removed variables
            $deletedCount = $this->deleteRemovedVariables($variables);
            if ($deletedCount > 0) {
                $changesMade = true;
            }

            // Update or create variables
            $updatedCount = $this->updateOrCreateVariables($variables);
            if ($updatedCount > 0) {
                $changesMade = true;
            }
        });

        // Only dispatch success after transaction has committed
        if ($changesMade) {
            $this->dispatch('success', 'Environment variables updated.');
        }
    }

    /**
     * Delete user-owned rows the admin dropped from the bulk textarea.
     *
     * Infisical-owned rows are never in the textarea, so they must never be
     * reachable here: the is_infisical_managed guard is the control that keeps a
     * missing key from hard-deleting a secret a running deployment depends on.
     */
    private function deleteRemovedVariables($variables)
    {
        $variablesToDelete = $this->environment->environment_variables()
            ->where('is_infisical_managed', false)
            ->whereNotIn('key', array_keys($variables))
            ->get();

        if ($variablesToDelete->isEmpty()) {
            return 0;
        }

        $this->environment->environment_variables()
            ->where('is_infisical_managed', false)
            ->whereNotIn('key', array_keys($variables))
            ->delete();

        return $variablesToDelete->count();
    }

    private function updateOrCreateVariables($variables)
    {
        $count = 0;
        foreach ($variables as $key => $data) {
            $value = is_array($data) ? ($data['value'] ?? '') : $data;

            $found = $this->environment->environment_variables()
                ->where('is_infisical_managed', false)
                ->where('key', $key)
                ->first();

            if ($found === null && $this->environment->environment_variables()->where('is_infisical_managed', true)->where('key', $key)->exists()) {
                // An Infisical-owned row already holds this key. Never shadow it
                // with a second row from the textarea.
                continue;
            }

            if ($found) {
                if (! $found->is_shown_once && ! $found->is_multiline) {
                    if ($found->value !== $value) {
                        $found->value = $value;
                        $found->save();
                        $count++;
                    }
                }
            } else {
                $this->environment->environment_variables()->create([
                    'key' => $key,
                    'value' => $value,
                    'is_multiline' => false,
                    'is_literal' => false,
                    'type' => 'environment',
                    'team_id' => currentTeam()->id,
                ]);
                $count++;
            }
        }

        return $count;
    }

    public function refreshEnvs()
    {
        $this->environment->refresh();
        $this->getDevView();
    }

    public function render()
    {
        return view('livewire.shared-variables.environment.show');
    }
}
