<?php

namespace App\Http\Controllers;

use App\Events\TestEvent;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Fortify;

class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    public function realtime_test()
    {
        if (auth()->user()?->currentTeam()->id !== 0) {
            return redirect(RouteServiceProvider::HOME);
        }
        TestEvent::dispatch();

        return 'Look at your other tab.';
    }

    public function verify()
    {
        return view('auth.verify-email');
    }

    public function email_verify(EmailVerificationRequest $request)
    {
        $request->fulfill();

        return redirect(RouteServiceProvider::HOME);
    }

    public function forgot_password(Request $request)
    {
        if (is_transactional_emails_enabled()) {
            $arrayOfRequest = $request->only(Fortify::email());
            $request->merge([
                'email' => Str::lower($arrayOfRequest['email']),
            ]);
            $type = set_transanctional_email_settings();
            if (blank($type)) {
                return response()->json(['message' => 'Transactional emails are not active'], 400);
            }
            $request->validate([Fortify::email() => 'required|email']);
            $status = Password::broker(config('fortify.passwords'))->sendResetLink(
                $request->only(Fortify::email())
            );
            if ($status == Password::RESET_LINK_SENT) {
                return app(SuccessfulPasswordResetLinkRequestResponse::class, ['status' => $status]);
            }
            if ($status == Password::RESET_THROTTLED) {
                return response('Already requested a password reset in the past minutes.', 400);
            }

            return app(FailedPasswordResetLinkRequestResponse::class, ['status' => $status]);
        }

        return response()->json(['message' => 'Transactional emails are not active'], 400);
    }

    public function link()
    {
        $token = request()->get('token');
        if ($token) {
            $decrypted = Crypt::decryptString($token);
            $email = str($decrypted)->before('@@@');
            $password = str($decrypted)->after('@@@');
            $user = User::whereEmail($email)->first();
            if (! $user) {
                return redirect()->route('login');
            }
            if (Hash::check($password, $user->password)) {
                $invitation = TeamInvitation::whereEmail($email);
                if ($invitation->exists()) {
                    $team = $invitation->first()->team;
                    $user->teams()->attach($team->id, ['role' => $invitation->first()->role]);
                    $invitation->delete();
                } else {
                    $team = $user->teams()->first();
                }
                if (is_null(data_get($user, 'email_verified_at'))) {
                    $user->email_verified_at = now();
                    $user->save();
                }
                Auth::login($user);
                session(['currentTeam' => $team]);

                return redirect()->route('dashboard');
            }
        }

        return redirect()->route('login')->with('error', 'Invalid credentials.');
    }

    public function acceptInvitation()
    {
        $resetPassword = request()->query('reset-password');
        $invitationUuid = request()->route('uuid');

        $invitation = TeamInvitation::whereUuid($invitationUuid)->firstOrFail();
        $user = User::whereEmail($invitation->email)->firstOrFail();

        if (Auth::id() !== $user->id) {
            abort(400, 'You are not allowed to accept this invitation.');
        }
        $invitationValid = $invitation->isValid();

        if ($invitationValid) {
            if ($resetPassword) {
                $user->update([
                    'password' => Hash::make($invitationUuid),
                    'force_password_reset' => true,
                ]);
            }
            if ($user->teams()->where('team_id', $invitation->team->id)->exists()) {
                $invitation->delete();

                return redirect()->route('team.index');
            }
            $user->teams()->attach($invitation->team->id, ['role' => $invitation->role]);
            $invitation->delete();

            refreshSession($invitation->team);

            return redirect()->route('team.index');
        } else {
            abort(400, 'Invitation expired.');
        }
    }

    public function revoke_invitation()
    {
        $invitation = TeamInvitation::whereUuid(request()->route('uuid'))->firstOrFail();
        $user = User::whereEmail($invitation->email)->firstOrFail();
        if (is_null(Auth::user())) {
            return redirect()->route('login');
        }
        if (Auth::id() !== $user->id) {
            abort(401);
        }
        $invitation->delete();

        return redirect()->route('team.index');
    }
}
