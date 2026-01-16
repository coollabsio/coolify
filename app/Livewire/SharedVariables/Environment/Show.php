<?php

namespace App\Livewire\SharedVariables\Environment;

use App\Models\Application;
use App\Models\Project;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
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
                'type' => 'environment',
                'team_id' => currentTeam()->id,
            ]);
            $this->environment->refresh();
            $this->getDevView();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function mount()
    {
        $this->parameters = get_route_parameters();
        $this->project = Project::ownedByCurrentTeam()->where('uuid', request()->route('project_uuid'))->firstOrFail();
        $this->environment = $this->project->environments()->where('uuid', request()->route('environment_uuid'))->firstOrFail();
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
        $this->variables = $this->formatEnvironmentVariables($this->environment->environment_variables->sortBy('key'));
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

    private function deleteRemovedVariables($variables)
    {
        $variablesToDelete = $this->environment->environment_variables()->whereNotIn('key', array_keys($variables))->get();

        if ($variablesToDelete->isEmpty()) {
            return 0;
        }

        $this->environment->environment_variables()->whereNotIn('key', array_keys($variables))->delete();

        return $variablesToDelete->count();
    }

    private function updateOrCreateVariables($variables)
    {
        $count = 0;
        foreach ($variables as $key => $value) {
            $found = $this->environment->environment_variables()->where('key', $key)->first();

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
