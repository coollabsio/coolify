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
            return redirect()->route('dashboard');
        }
        if (Auth::id() !== 0 && ! session('impersonating')) {
            return redirect()->route('dashboard');
        }
        $this->getSubscribers();

        return null;
    }

    public function back()
    {
        if (session('impersonating')) {
            session()->forget('impersonating');
            $user = User::query()->find(0);
            $team_to_switch_to = $user->teams->first();
            Auth::login($user);
            refreshSession($team_to_switch_to);

            return redirect(request()->header('Referer'));
        }

        return null;
    }

    public function submitSearch()
    {
        if ($this->search !== '') {
            $this->foundUsers = User::query()->where(function ($query) {
                $query->where('name', 'like', "%{$this->search}%")
                    ->orWhere('email', 'like', "%{$this->search}%");
            })->get();
        }
    }

    public function getSubscribers()
    {
        $this->inactiveSubscribers = Team::query()->whereRelation('subscription', 'stripe_invoice_paid', false)->count();
        $this->activeSubscribers = Team::query()->whereRelation('subscription', 'stripe_invoice_paid', true)->count();
    }

    public function switchUser(int $user_id)
    {
        if (Auth::id() !== 0) {
            return redirect()->route('dashboard');
        }
        session(['impersonating' => true]);
        $user = User::query()->find($user_id);
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
