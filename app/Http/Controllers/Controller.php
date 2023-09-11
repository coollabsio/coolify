<?php

namespace App\Http\Controllers;

use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\StandalonePostgresql;
use App\Models\TeamInvitation;
use App\Models\User;
use Auth;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Throwable;
use Str;


class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    public function link()
    {
        $token = request()->get('token');
        if ($token) {
            $decrypted = Crypt::decryptString($token);
            $email = Str::of($decrypted)->before('@@@');
            $password = Str::of($decrypted)->after('@@@');
            $user = User::whereEmail($email)->first();
            if (!$user) {
                return redirect()->route('login');
            }
            if (Hash::check($password, $user->password)) {
                Auth::login($user);
                $team = $user->teams()->first();
                session(['currentTeam' => $team]);
                return redirect()->route('dashboard');
            }
        }
        return redirect()->route('login')->with('error', 'Invalid credentials.');
    }
    public function subscription()
    {
        if (!isCloud()) {
            abort(404);
        }
        return view('subscription.index', [
            'settings' => InstanceSettings::get(),
        ]);
    }

    public function license()
    {
        if (!isCloud()) {
            abort(404);
        }
        return view('settings.license', [
            'settings' => InstanceSettings::get(),
        ]);
    }

    public function force_passoword_reset()
    {
        return view('auth.force-password-reset');
    }
    public function boarding()
    {
        if (currentTeam()->boarding || isDev()) {
            return view('boarding');
        } else {
            return redirect()->route('dashboard');
        }
    }

    public function settings()
    {
        if (isInstanceAdmin()) {
            $settings = InstanceSettings::get();
            $database = StandalonePostgresql::whereName('coolify-db')->first();
            if ($database) {
                $s3s = S3Storage::whereTeamId(0)->get();
            }
            return view('settings.configuration', [
                'settings' => $settings,
                'database' => $database,
                's3s' => $s3s ?? [],
            ]);
        } else {
            return redirect()->route('dashboard');
        }
    }

    public function team()
    {
        $invitations = [];
        if (auth()->user()->isAdminFromSession()) {
            $invitations = TeamInvitation::whereTeamId(currentTeam()->id)->get();
        }
        return view('team.index', [
            'invitations' => $invitations,
        ]);
    }

    public function storages()
    {
        $s3 = S3Storage::ownedByCurrentTeam()->get();
        return view('team.storages.all', [
            's3' => $s3,
        ]);
    }

    public function storages_show()
    {
        $storage = S3Storage::ownedByCurrentTeam()->whereUuid(request()->storage_uuid)->firstOrFail();
        return view('team.storages.show', [
            'storage' => $storage,
        ]);
    }

    public function members()
    {
        $invitations = [];
        if (auth()->user()->isAdminFromSession()) {
            $invitations = TeamInvitation::whereTeamId(currentTeam()->id)->get();
        }
        return view('team.members', [
            'invitations' => $invitations,
        ]);
    }

    public function acceptInvitation()
    {
        try {
            $invitation = TeamInvitation::whereUuid(request()->route('uuid'))->firstOrFail();
            $user = User::whereEmail($invitation->email)->firstOrFail();
            if (is_null(auth()->user())) {
                return redirect()->route('login');
            }
            if (auth()->user()->id !== $user->id) {
                abort(401);
            }

            $createdAt = $invitation->created_at;
            $diff = $createdAt->diffInMinutes(now());
            if ($diff <= config('constants.invitation.link.expiration')) {
                $user->teams()->attach($invitation->team->id, ['role' => $invitation->role]);
                $invitation->delete();
                return redirect()->route('team.index');
            } else {
                $invitation->delete();
                abort(401);
            }
        } catch (Throwable $e) {
            throw $e;
        }
    }

    public function revokeInvitation()
    {
        try {
            $invitation = TeamInvitation::whereUuid(request()->route('uuid'))->firstOrFail();
            $user = User::whereEmail($invitation->email)->firstOrFail();
            if (is_null(auth()->user())) {
                return redirect()->route('login');
            }
            if (auth()->user()->id !== $user->id) {
                abort(401);
            }
            $invitation->delete();
            return redirect()->route('team.index');
        } catch (Throwable $e) {
            throw $e;
        }
    }
}
