<?php

namespace App\Livewire\Waitlist;

use App\Jobs\SendConfirmationForWaitlistJob;
use App\Models\User;
use App\Models\Waitlist;
use Illuminate\Support\Str;
use Livewire\Component;

class Index extends Component
{
    public string $email;

    public int $users = 0;

    public int $waitingInLine = 0;

    protected $rules = [
        'email' => 'required|email',
    ];

    public function render()
    {
        return view('livewire.waitlist.index')->layout('layouts.simple');
    }

    public function mount()
    {
        if (config('coolify.waitlist') == false) {
            return redirect()->route('register');
        }
        $this->waitingInLine = Waitlist::whereVerified(true)->count();
        $this->users = User::count();
        if (isDev()) {
            $this->email = 'waitlist@example.com';
        }
    }

    public function submit()
    {
        $this->validate();
        try {
            $already_registered = User::whereEmail($this->email)->first();
            if ($already_registered) {
                throw new \Exception('You are already on the waitlist or registered. <br>Please check your email to verify your email address or contact support.');
            }
            $found = Waitlist::where('email', $this->email)->first();
            if ($found) {
                if (! $found->verified) {
                    $this->dispatch('error', 'You are already on the waitlist. <br>Please check your email to verify your email address.');

                    return;
                }
                $this->dispatch('error', 'You are already on the waitlist. <br>You will be notified when your turn comes. <br>Thank you.');

                return;
            }
            $waitlist = Waitlist::create([
                'email' => Str::lower($this->email),
                'type' => 'registration',
            ]);

            $this->dispatch('success', 'Check your email to verify your email address.');
            dispatch(new SendConfirmationForWaitlistJob($this->email, $waitlist->uuid));
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
