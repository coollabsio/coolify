<?php

namespace App\Livewire\Admin;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Livewire\Component;

class Index extends Component
{
    public $users = [];
    public function mount()
    {
        if (auth()->user()->id !== 0) {
            return redirect()->route('dashboard');
        }
        $this->users = User::whereHas('teams', function ($query) {
            $query->whereRelation('subscription', 'stripe_subscription_id', '!=', null);
        })->get();
    }
    public function switchUser(int $user_id)
    {
        $user = User::find($user_id);
        auth()->login($user);

        if ($user_id === 0) {
            session()->forget('adminToken');
        } else {
            $token_payload = [
                'valid' => true,
            ];
            $token = Crypt::encrypt($token_payload);
            session(['adminToken' => $token]);
        }
        return refreshSession();
    }
    public function render()
    {
        return view('livewire.admin.index');
    }
}
