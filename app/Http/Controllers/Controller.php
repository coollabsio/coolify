<?php

namespace App\Http\Controllers;

use App\Events\TestEvent;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
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

    public function hawcertLogin(Request $request)
    {
        $baseUrl = rtrim((string) config('services.hawcert.base_url'), '/');
        if ($baseUrl === '') {
            return back()->with('error', 'HawCert no está configurado en esta instancia.');
        }

        $urlForValidation = $request->getSchemeAndHttpHost().'/';

        if ($request->hasFile('certificate')) {
            $request->validate([
                'certificate' => [
                    'required',
                    'file',
                    'max:512',
                    function (string $attribute, mixed $value, \Closure $fail): void {
                        if (! $value instanceof \Illuminate\Http\UploadedFile || ! $value->isValid()) {
                            $fail('El archivo de certificado no es válido.');

                            return;
                        }
                        $ext = strtolower($value->getClientOriginalExtension() ?? '');
                        if (! in_array($ext, ['pem', 'crt', 'cer', 'txt'], true)) {
                            $fail('El archivo debe ser .pem (o .crt/.cer).');
                        }
                    },
                ],
            ], [], [
                'certificate' => 'certificado',
            ]);

            $pem = (string) file_get_contents($request->file('certificate')->getRealPath());
            if (! str_contains($pem, 'BEGIN')) {
                return back()->with('error', 'El archivo no parece un certificado PEM válido.');
            }

            $accessResponse = Http::timeout(20)->acceptJson()->asJson()->post($baseUrl.'/api/validate-access', [
                'certificate' => $pem,
                'url' => $urlForValidation,
            ]);

            if (! $accessResponse->successful()) {
                $message = (string) data_get($accessResponse->json(), 'message');

                return back()->with('error', $message !== '' ? $message : 'HawCert no pudo validar el certificado.');
            }

            $accessKey = (string) data_get($accessResponse->json(), 'access_key');
            if (strlen($accessKey) !== 51 || ! str_starts_with($accessKey, 'ak_')) {
                return back()->with('error', 'Respuesta inválida de HawCert (access_key).');
            }

            $response = Http::timeout(10)->acceptJson()->asJson()->post($baseUrl.'/api/validate-key', [
                'key' => $accessKey,
                'url' => $urlForValidation,
            ]);
        } elseif ($request->filled('key')) {
            $request->validate([
                'key' => ['required', 'string', 'size:51'],
            ]);

            $response = Http::timeout(10)->acceptJson()->asJson()->post($baseUrl.'/api/validate-key', [
                'key' => $request->string('key')->toString(),
                'url' => $urlForValidation,
            ]);
        } else {
            return back()->withErrors([
                'certificate' => 'Sube tu certificado (.pem) o introduce una access key.',
            ]);
        }

        if (! $response->successful()) {
            $message = (string) data_get($response->json(), 'message');

            return back()->with('error', $message !== '' ? $message : 'No se pudo validar el acceso.');
        }

        $email = (string) data_get($response->json(), 'user.email');
        if (blank($email)) {
            return back()->with('error', 'Respuesta inválida de HawCert (falta user.email).');
        }

        return $this->finalizeHawcertLogin(Str::lower($email));
    }

    private function finalizeHawcertLogin(string $email): RedirectResponse
    {
        $user = User::where('email', $email)->with('teams')->first();
        if (! $user) {
            return back()->with('error', 'Usuario no encontrado en Coolify para este certificado.');
        }

        $user->updated_at = now();
        $user->save();

        $invitation = TeamInvitation::whereEmail($email)->first();
        if ($invitation && $invitation->isValid()) {
            if (! $user->teams()->where('team_id', $invitation->team->id)->exists()) {
                $user->teams()->attach($invitation->team->id, ['role' => $invitation->role]);
            }
            $user->currentTeam = $invitation->team;
            $invitation->delete();
        } else {
            $user->currentTeam = $user->teams->firstWhere('personal_team', true);
            if (! $user->currentTeam) {
                $user->currentTeam = $user->recreate_personal_team();
            }
        }

        Auth::login($user);
        refreshSession($user->currentTeam);

        return redirect()->route('dashboard');
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
                // force_password_reset is guarded on the User model; set it
                // via direct property assignment so it is not silently
                // dropped by the mass-assignment filter.
                $user->password = Hash::make($invitationUuid);
                $user->force_password_reset = true;
                $user->save();
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

    public function revokeInvitation()
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
