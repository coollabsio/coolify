<?php

namespace App\Http\Livewire;

use App\Jobs\SendConfirmationForWaitlistJob;
use App\Models\User;
use App\Models\Waitlist as ModelsWaitlist;
use Livewire\Component;

class Waitlist extends Component
{
    public string $email;
    public int $waiting_in_line = 0;

    protected $rules = [
        'email' => 'required|email',
    ];
    public function mount()
    {
        if (is_dev()) {
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
            $found = ModelsWaitlist::where('email', $this->email)->first();
            if ($found) {
                if (!$found->verified) {
                    $this->emit('error', 'You are already on the waitlist. <br>Please check your email to verify your email address.');
                    return;
                }
                $this->emit('error', 'You are already on the waitlist. <br>You will be notified when your turn comes. <br>Thank you.');
                return;
            }
            $waitlist = ModelsWaitlist::create([
                'email' => $this->email,
                'type' => 'registration',
            ]);

            $this->emit('success', 'Check your email to verify your email address.');
            dispatch(new SendConfirmationForWaitlistJob($this->email, $waitlist->uuid));
        } catch (\Exception $e) {
            return general_error_handler(err: $e, that: $this);
        }
    }
}
