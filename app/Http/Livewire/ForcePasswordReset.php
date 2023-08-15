<?php

namespace App\Http\Livewire;

use Illuminate\Support\Facades\Hash;
use Livewire\Component;

class ForcePasswordReset extends Component
{
    public string $email;
    public string $password;
    public string $password_confirmation;

    protected $rules = [
        'email' => 'required|email',
        'password' => 'required|min:8',
        'password_confirmation' => 'required|same:password',
    ];
    public function mount() {
        $this->email = auth()->user()->email;
    }
    public function submit() {
        try {
            $this->validate();
            auth()->user()->forceFill([
                'password' => Hash::make($this->password),
                'force_password_reset' => false,
            ])->save();
            auth()->logout();
            return redirect()->route('login')->with('status', 'Your initial password has been set.');
        } catch(\Exception $e) {
            return general_error_handler(err:$e, that:$this);
        }
    }

}
