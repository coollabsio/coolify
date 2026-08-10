<?php

namespace App\Livewire\Team;

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Index extends Component
{
    use AuthorizesRequests;

    public $invitations = [];

    public Team $team;

    // Explicit properties
    public string $name;

    public ?string $description = null;

    public bool $is_mcp_server_enabled = true;

    protected function rules(): array
    {
        return [
            'name' => ValidationPatterns::nameRules(),
            'description' => ValidationPatterns::descriptionRules(),
            'is_mcp_server_enabled' => 'boolean',
        ];
    }

    protected function messages(): array
    {
        return array_merge(
            ValidationPatterns::combinedMessages(),
            [
                'name.required' => 'The Name field is required.',
            ]
        );
    }

    protected $validationAttributes = [
        'name' => 'name',
        'description' => 'description',
    ];

    /**
     * Sync data between component properties and model
     *
     * @param  bool  $toModel  If true, sync FROM properties TO model. If false, sync FROM model TO properties.
     */
    private function syncData(bool $toModel = false): void
    {
        if ($toModel) {
            // Sync TO model (before save)
            $this->team->name = $this->name;
            $this->team->description = $this->description;
            $this->team->is_mcp_server_enabled = $this->is_mcp_server_enabled;
        } else {
            // Sync FROM model (on load/refresh)
            $this->name = $this->team->name;
            $this->description = $this->team->description;
            // Null can appear after Team::create() when the DB default is not
            // hydrated onto the in-memory model stored in session.
            $this->is_mcp_server_enabled = (bool) ($this->team->is_mcp_server_enabled ?? true);
        }
    }

    public function mount()
    {
        $this->team = currentTeam();
        $this->syncData(false);

        if (auth()->user()->isAdminFromSession()) {
            $this->invitations = TeamInvitation::whereTeamId(currentTeam()->id)->get();
        }
    }

    public function render()
    {
        return view('livewire.team.index');
    }

    public function submit()
    {
        $this->validate();
        try {
            $this->authorize('update', $this->team);
            $this->syncData(true);
            $this->team->save();
            refreshSession();
            $this->dispatch('success', 'Team updated.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
