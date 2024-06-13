<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Models\Waitlist as ModelsWaitlist;
use Exception;
use Illuminate\Http\Request;

class Waitlist extends Controller
{
    public function confirm(Request $request)
    {
        $email = request()->get('email');
        $confirmation_code = request()->get('confirmation_code');
        ray($email, $confirmation_code);
        try {
            $found = ModelsWaitlist::where('uuid', $confirmation_code)->where('email', $email)->first();
            if ($found) {
                if (! $found->verified) {
                    if ($found->created_at > now()->subMinutes(config('constants.waitlist.expiration'))) {
                        $found->verified = true;
                        $found->save();
                        send_internal_notification('Waitlist confirmed: '.$email);

                        return 'Thank you for confirming your email address. We will notify you when you are next in line.';
                    } else {
                        $found->delete();
                        send_internal_notification('Waitlist expired: '.$email);

                        return 'Your confirmation code has expired. Please sign up again.';
                    }
                }
            }

            return redirect()->route('dashboard');
        } catch (Exception $e) {
            send_internal_notification('Waitlist confirmation failed: '.$e->getMessage());
            ray($e->getMessage());

            return redirect()->route('dashboard');
        }
    }

    public function cancel(Request $request)
    {
        $email = request()->get('email');
        $confirmation_code = request()->get('confirmation_code');
        try {
            $found = ModelsWaitlist::where('uuid', $confirmation_code)->where('email', $email)->first();
            if ($found && ! $found->verified) {
                $found->delete();
                send_internal_notification('Waitlist cancelled: '.$email);

                return 'Your email address has been removed from the waitlist.';
            }

            return redirect()->route('dashboard');
        } catch (Exception $e) {
            send_internal_notification('Waitlist cancellation failed: '.$e->getMessage());
            ray($e->getMessage());

            return redirect()->route('dashboard');
        }
    }
}
