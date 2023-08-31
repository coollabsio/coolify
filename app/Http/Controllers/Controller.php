<?php

namespace App\Http\Controllers;

use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\Server;
use App\Models\StandalonePostgresql;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Models\Waitlist;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;
use Throwable;

class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    public function waitlist() {
        $waiting_in_line = Waitlist::whereVerified(true)->count();
        return view('auth.waitlist', [
            'waiting_in_line' => $waiting_in_line,
        ]);
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

    public function force_passoword_reset() {
        return view('auth.force-password-reset');
    }
    public function boarding() {
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
        } catch (Throwable $th) {
            throw $th;
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
        } catch (Throwable $th) {
            throw $th;
        }
    }
}
