<?php

namespace App\Livewire\Team;

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class Index extends Component
{
    use AuthorizesRequests;

    public $invitations = [];

    public Team $team;

    protected function rules(): array
    {
        return [
            'team.name' => ValidationPatterns::nameRules(),
            'team.description' => ValidationPatterns::descriptionRules(),
        ];
    }

    protected function messages(): array
    {
        return array_merge(
            ValidationPatterns::combinedMessages(),
            [
                'team.name.required' => 'The Name field is required.',
                'team.name.regex' => 'The Name may only contain letters, numbers, spaces, dashes (-), underscores (_), dots (.), slashes (/), colons (:), and parentheses ().',
                'team.description.regex' => 'The Description contains invalid characters. Only letters, numbers, spaces, and common punctuation (- _ . : / () \' " , ! ? @ # % & + = [] {} | ~ ` *) are allowed.',
            ]
        );
    }

    protected $validationAttributes = [
        'team.name' => 'name',
        'team.description' => 'description',
    ];

    public function mount()
    {
        $this->team = currentTeam();

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
            $this->team->save();
            refreshSession();
            $this->dispatch('success', 'Team updated.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function delete()
    {
        $currentTeam = currentTeam();
        $this->authorize('delete', $currentTeam);
        $currentTeam->delete();

        $currentTeam->members->each(function ($user) use ($currentTeam) {
            if ($user->id === Auth::id()) {
                return;
            }
            $user->teams()->detach($currentTeam);
            $session = DB::table('sessions')->where('user_id', $user->id)->first();
            if ($session) {
                DB::table('sessions')->where('id', $session->id)->delete();
            }
        });

        refreshSession();

        return redirect()->route('team.index');
    }
}
