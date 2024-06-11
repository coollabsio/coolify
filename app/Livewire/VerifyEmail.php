<?php

namespace App\Livewire;

use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Livewire\Component;

class VerifyEmail extends Component
{
    use WithRateLimiting;

    public function again()
    {
        try {
            $this->rateLimit(1, 300);
            auth()->user()->sendVerificationEmail();
            $this->dispatch('success', 'Email verification link sent!');

        } catch (\Exception $e) {
            ray($e);

            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.verify-email');
    }
}
