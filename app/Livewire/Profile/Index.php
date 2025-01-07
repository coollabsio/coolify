<?php

namespace App\Livewire\Profile;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Throwable;

class Index extends Component
{
    public int $userId;

    public string $email;

    public string $current_password;

    public string $new_password;

    public string $new_password_confirmation;

    #[Validate('required')]
    public string $name;

    public function mount()
    {
        $this->userId = Auth::id();
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;
    }

    public function submit()
    {
        try {
            $this->validate([
                'name' => 'required',
            ]);
            Auth::user()->update([
                'name' => $this->name,
            ]);

            $this->dispatch('success', 'Profile updated.');
        } catch (Throwable $e) {
            return handleError($e, $this);
        }

        return null;
    }

    public function resetPassword()
    {
        try {
            $this->validate([
                'current_password' => ['required'],
                'new_password' => ['required', Password::defaults(), 'confirmed'],
            ]);
            if (! Hash::check($this->current_password, auth()->user()->password)) {
                $this->dispatch('error', 'Current password is incorrect.');

                return null;
            }
            if ($this->new_password !== $this->new_password_confirmation) {
                $this->dispatch('error', 'The two new passwords does not match.');

                return null;
            }
            auth()->user()->update([
                'password' => Hash::make($this->new_password),
            ]);
            $this->dispatch('success', 'Password updated.');
            $this->current_password = '';
            $this->new_password = '';
            $this->new_password_confirmation = '';
        } catch (Throwable $e) {
            return handleError($e, $this);
        }

        return null;
    }

    public function render()
    {
        return view('livewire.profile.index');
    }
}
