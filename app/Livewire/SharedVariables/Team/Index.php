<?php

namespace App\Livewire\SharedVariables\Team;

use App\Livewire\Concerns\RemembersCanvasView;
use App\Models\SharedEnvironmentVariable;
use App\Models\Team;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class Index extends Component
{
    use AuthorizesRequests;
    use RemembersCanvasView;

    public Team $team;

    public string $view = 'normal';

    public ?string $variables = null;

    protected $listeners = ['refreshEnvs' => 'refreshEnvs', 'saveKey' => 'saveKey', 'environmentVariableDeleted' => 'refreshEnvs'];

    public function saveKey($data)
    {
        try {
            $this->authorize('update', $this->team);

            $found = $this->team->environment_variables()->where('key', $data['key'])->first();
            if ($found) {
                throw new \Exception('Variable already exists.');
            }
            $this->team->environment_variables()->create([
                'key' => $data['key'],
                'value' => $data['value'],
                'is_multiline' => $data['is_multiline'],
                'is_literal' => $data['is_literal'],
                'comment' => $data['comment'] ?? null,
                'type' => 'team',
                'team_id' => $this->team->id,
            ]);
            $this->team->refresh();
            $this->getDevView();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function mount()
    {
        if ($this->prefersCanvasView('team_variables')) {
            return $this->redirectRoute('shared-variables.team.canvas', navigate: true);
        }
        $this->team = currentTeam();
        $this->getDevView();
    }

    public function switch()
    {
        try {
            $this->authorize('view', $this->team);
            $this->view = $this->view === 'normal' ? 'dev' : 'normal';
            $this->getDevView();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function getDevView()
    {
        $this->variables = $this->formatEnvironmentVariables($this->team->environment_variables->sortBy('key'));
    }

    private function formatEnvironmentVariables($variables)
    {
        $canViewValues = auth()->user()?->isAdminOfTeam($this->team->id) ?? false;

        return $variables->map(fn ($item) => formatEnvVariableForDevView($item, $canViewValues))->join("\n");
    }

    public function submit()
    {
        // Parse before the save, so that an invalid "# coolify:" line changes nothing and keeps the edited text.
        try {
            $this->authorize('update', $this->team);
            $variables = parseEnvDevView($this->variables, SharedEnvironmentVariable::DEV_VIEW_FLAG_DEFAULTS);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }

        try {
            $this->handleBulkSubmit($variables);
            $this->getDevView();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        } finally {
            $this->refreshEnvs();
        }
    }

    private function handleBulkSubmit(array $variables)
    {
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

        if ($changesMade) {
            $this->dispatch('success', 'Environment variables updated.');
        }
    }

    private function deleteRemovedVariables($variables)
    {
        $variablesToDelete = $this->team->environment_variables()->whereNotIn('key', array_keys($variables))->get();

        if ($variablesToDelete->isEmpty()) {
            return 0;
        }

        $this->team->environment_variables()->whereNotIn('key', array_keys($variables))->delete();

        return $variablesToDelete->count();
    }

    private function updateOrCreateVariables($variables)
    {
        $count = 0;
        foreach ($variables as $key => $data) {
            $value = is_array($data) ? ($data['value'] ?? '') : $data;

            $found = $this->team->environment_variables()->where('key', $key)->first();

            if ($found) {
                if (! $found->is_shown_once && ! $found->is_multiline && formatEnvValueForDevView($found->value) !== null) {
                    if ($found->value !== $value) {
                        $found->value = $value;
                    }
                    $found->fill($data['settings']);
                    if ($found->isDirty()) {
                        $found->save();
                        $count++;
                    }
                }
            } else {
                $this->team->environment_variables()->create([
                    'key' => $key,
                    'value' => $value,
                    'is_multiline' => false,
                    'is_literal' => false,
                    ...$data['settings'],
                    'type' => 'team',
                    'team_id' => $this->team->id,
                ]);
                $count++;
            }
        }

        return $count;
    }

    public function refreshEnvs()
    {
        $this->team->refresh();
        $this->getDevView();
    }

    public function render()
    {
        return view('livewire.shared-variables.team.index');
    }
}
