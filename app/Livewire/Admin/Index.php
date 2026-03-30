<?php

namespace App\Livewire\Admin;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Index extends Component
{
    public int $activeSubscribers;

    public int $inactiveSubscribers;

    public Collection $foundUsers;

    public string $search = '';

    public function mount()
    {
        if (! isCloud() && ! isDev()) {
            abort(403);
        }
        $this->authorizeAdminAccess();
        $this->getSubscribers();
    }

    public function back()
    {
        $this->authorizeAdminAccess();
        if (session('impersonating')) {
            session()->forget('impersonating');
            $user = User::find(0);
            $team_to_switch_to = $user->teams->first();
            Auth::login($user);
            refreshSession($team_to_switch_to);

            return redirect(request()->header('Referer'));
        }
    }

    public function submitSearch()
    {
        $this->authorizeAdminAccess();
        if ($this->search !== '') {
            $this->foundUsers = User::where(function ($query) {
                $query->where('name', 'like', "%{$this->search}%")
                    ->orWhere('email', 'like', "%{$this->search}%");
            })->get();
        }
    }

    public function getSubscribers()
    {
        $this->inactiveSubscribers = Team::whereRelation('subscription', 'stripe_invoice_paid', false)->count();
        $this->activeSubscribers = Team::whereRelation('subscription', 'stripe_invoice_paid', true)->count();
    }

    public function switchUser(int $user_id)
    {
        $this->authorizeRootOnly();
        session(['impersonating' => true]);
        $user = User::find($user_id);
        if (! $user) {
            abort(404);
        }
        $team_to_switch_to = $user->teams->first();
        Auth::login($user);
        refreshSession($team_to_switch_to);

        return redirect(request()->header('Referer'));
    }

    private function authorizeAdminAccess(): void
    {
        if (! Auth::check() || (Auth::id() !== 0 && ! session('impersonating'))) {
            abort(403);
        }
    }

    private function authorizeRootOnly(): void
    {
        if (! Auth::check() || Auth::id() !== 0) {
            abort(403);
        }
    }

    public function render()
    {
        return view('livewire.admin.index');
    }
}
