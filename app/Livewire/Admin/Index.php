<?php

namespace App\Livewire\Admin;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
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
            return redirect()->route('dashboard');
        }
        if (Auth::id() !== 0 && ! session('impersonating')) {
            return redirect()->route('dashboard');
        }
        $this->getSubscribers();
    }

    public function back()
    {
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
        if (Auth::id() !== 0) {
            return redirect()->route('dashboard');
        }
        session(['impersonating' => true]);
        $user = User::find($user_id);
        $team_to_switch_to = $user->teams->first();
        // Cache::forget("team:{$user->id}");
        Auth::login($user);
        refreshSession($team_to_switch_to);

        return redirect(request()->header('Referer'));
    }

    public function render()
    {
        return view('livewire.admin.index');
    }
}
