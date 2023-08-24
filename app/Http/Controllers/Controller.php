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
        if (!is_cloud()) {
            abort(404);
        }
        return view('subscription.show', [
            'settings' => InstanceSettings::get(),
        ]);
    }

    public function license()
    {
        if (!is_cloud()) {
            abort(404);
        }
        return view('settings.license', [
            'settings' => InstanceSettings::get(),
        ]);
    }

    public function force_passoword_reset() {
        return view('auth.force-password-reset');
    }
    public function dashboard()
    {
        $projects = Project::ownedByCurrentTeam()->get();
        $servers = Server::ownedByCurrentTeam()->get();
        $s3s = S3Storage::ownedByCurrentTeam()->get();
        $resources = 0;
        foreach ($projects as $project) {
            $resources += $project->applications->count();
            $resources += $project->postgresqls->count();
        }
        return view('dashboard', [
            'servers' => $servers->count(),
            'projects' => $projects->count(),
            'resources' => $resources,
            's3s' => $s3s,
        ]);
    }
    public function boarding() {
        if (currentTeam()->boarding || is_dev()) {
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
        return view('team.show', [
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
                return redirect()->route('team.show');
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
            return redirect()->route('team.show');
        } catch (Throwable $th) {
            throw $th;
        }
    }
}
