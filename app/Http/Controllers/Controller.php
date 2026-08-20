<?php

namespace App\Http\Controllers;

use App\Events\TestEvent;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
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

    public function email_verify(Request $request)
    {
        if (! $request->hasValidSignature()) {
            abort(403);
        }

        $user = auth()->user();
        if (! $user) {
            abort(403);
        }

        if (! hash_equals((string) $request->route('id'), (string) $user->getKey())) {
            abort(403);
        }

        if (! hash_equals((string) $request->route('hash'), hash('sha256', $user->getEmailForVerification()))) {
            abort(403);
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
        }

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

    public function link(): View|RedirectResponse
    {
        $token = request()->get('token');
        $credentials = is_string($token) ? $this->magicLinkCredentials($token) : null;
        if (! $credentials) {
            return redirect()->route('login')->with('error', 'Invitation has expired or been revoked.');
        }

        [$user, $invitation] = $credentials;

        return view('invitation.accept', [
            'invitation' => $invitation,
            'team' => $invitation->team,
            'alreadyMember' => $user->teams()->where('team_id', $invitation->team_id)->exists(),
            'formAction' => route('auth.link.accept'),
            'token' => $token,
        ]);
    }

    public function acceptLink(Request $request): RedirectResponse
    {
        $token = $request->input('token');
        if (! is_string($token)) {
            return redirect()->route('login')->with('error', 'Invitation has expired or been revoked.');
        }

        $acceptedInvitation = DB::transaction(function () use ($token) {
            $credentials = $this->magicLinkCredentials($token, lockForUpdate: true);
            if (! $credentials) {
                return null;
            }

            [$user, $invitation] = $credentials;
            $team = $invitation->team;
            if (! $user->teams()->where('team_id', $team->id)->exists()) {
                $user->teams()->attach($team->id, ['role' => $invitation->role]);
            }

            $user->forceFill([
                'password' => Hash::make(Str::random(64)),
            ])->save();
            $invitation->delete();

            return [$user, $team];
        });

        if (! $acceptedInvitation) {
            return redirect()->route('login')->with('error', 'Invitation has expired or been revoked.');
        }

        [$user, $team] = $acceptedInvitation;

        Auth::login($user);
        session(['currentTeam' => $team]);

        return redirect()->route('dashboard');
    }

    /**
     * @return array{0: User, 1: TeamInvitation}|null
     */
    private function magicLinkCredentials(string $token, bool $lockForUpdate = false): ?array
    {
        if ($token === '') {
            return null;
        }

        try {
            $decrypted = Crypt::decryptString($token);
        } catch (DecryptException) {
            return null;
        }

        $payload = explode('@@@', $decrypted, 3);
        if (count($payload) === 3) {
            [$email, $invitationUuid, $password] = $payload;
        } elseif (count($payload) === 2) {
            [$email, $password] = $payload;
            $invitationUuid = null;
        } else {
            return null;
        }

        $email = Str::lower($email);
        $user = User::query()->where('email', $email)->first();
        $invitationQuery = TeamInvitation::query()
            ->where('email', $email)
            ->when($lockForUpdate, fn ($query) => $query->lockForUpdate());
        $invitation = $invitationUuid
            ? $invitationQuery->where('uuid', $invitationUuid)->first()
            : $invitationQuery->get()->first(
                fn (TeamInvitation $invitation) => $this->invitationLinkMatchesToken($invitation, $token)
            );

        if (! $user || ! $invitation || $invitation->hasExpired() || ! $this->invitationLinkMatchesToken($invitation, $token)) {
            return null;
        }

        return Hash::check($password, $user->password) ? [$user, $invitation] : null;
    }

    private function invitationLinkMatchesToken(TeamInvitation $invitation, string $token): bool
    {
        $query = parse_url($invitation->link, PHP_URL_QUERY);
        if (! is_string($query)) {
            return false;
        }

        parse_str($query, $parameters);
        $storedToken = $parameters['token'] ?? null;

        return is_string($storedToken) && hash_equals($storedToken, $token);
    }

    public function showInvitation()
    {
        $invitationUuid = request()->route('uuid');
        $invitation = TeamInvitation::whereUuid($invitationUuid)->firstOrFail();
        $user = User::whereEmail($invitation->email)->firstOrFail();

        if (Auth::id() !== $user->id) {
            abort(400, 'You are not allowed to accept this invitation.');
        }

        if (! $invitation->isValid()) {
            abort(400, 'Invitation expired.');
        }

        $alreadyMember = $user->teams()->where('team_id', $invitation->team->id)->exists();

        return view('invitation.accept', [
            'invitation' => $invitation,
            'team' => $invitation->team,
            'alreadyMember' => $alreadyMember,
            'formAction' => route('team.invitation.accept', $invitation->uuid),
        ]);
    }

    public function acceptInvitation()
    {
        $invitationUuid = request()->route('uuid');

        $invitation = TeamInvitation::whereUuid($invitationUuid)->firstOrFail();
        $user = User::whereEmail($invitation->email)->firstOrFail();

        if (Auth::id() !== $user->id) {
            abort(400, 'You are not allowed to accept this invitation.');
        }

        if (! $invitation->isValid()) {
            abort(400, 'Invitation expired.');
        }

        if ($user->teams()->where('team_id', $invitation->team->id)->exists()) {
            $invitation->delete();

            return redirect()->route('team.index');
        }
        $user->teams()->attach($invitation->team->id, ['role' => $invitation->role]);
        $invitation->delete();

        refreshSession($invitation->team);

        return redirect()->route('team.index');
    }
}
