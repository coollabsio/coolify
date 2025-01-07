<?php

namespace App\Livewire;

use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Livewire\Component;

class ForcePasswordReset extends Component
{
    use WithRateLimiting;

    public string $email;

    public string $password;

    public string $password_confirmation;

    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ];
    }

    public function mount()
    {
        if (auth()->user()->force_password_reset === false) {
            return redirect()->route('dashboard');
        }
        $this->email = auth()->user()->email;
    }

    public function render()
    {
        return view('livewire.force-password-reset')->layout('layouts.simple');
    }

    public function submit()
    {
        if (auth()->user()->force_password_reset === false) {
            return redirect()->route('dashboard');
        }

        try {
            $this->rateLimit(10);
            $this->validate();
            $firstLogin = auth()->user()->created_at == auth()->user()->updated_at;
            auth()->user()->forceFill([
                'password' => Hash::make($this->password),
                'force_password_reset' => false,
            ])->save();
            if ($firstLogin) {
                send_internal_notification('First login for '.auth()->user()->email);
            }

            return redirect()->route('dashboard');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
