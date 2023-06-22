<?php

namespace App\Http\Controllers;

use App\Http\Livewire\Team\Invitations;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\DB;

class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    public function license()
    {
        return view('license');
    }
    public function dashboard()
    {
        $projects = Project::ownedByCurrentTeam()->get();
        $servers = Server::ownedByCurrentTeam()->get();

        $resources = 0;
        foreach ($projects as $project) {
            $resources += $project->applications->count();
        }

        return view('dashboard', [
            'servers' => $servers->count(),
            'projects' => $projects->count(),
            'resources' => $resources,
        ]);
    }
    public function settings()
    {
        if (auth()->user()->isInstanceAdmin()) {
            $settings = InstanceSettings::get();
            return view('settings.configuration', [
                'settings' => $settings
            ]);
        } else {
            return redirect()->route('dashboard');
        }
    }
    public function emails()
    {
        if (auth()->user()->isInstanceAdmin()) {
            $settings = InstanceSettings::get();
            return view('settings.emails', [
                'settings' => $settings
            ]);
        } else {
            return redirect()->route('dashboard');
        }
    }
    public function team()
    {
        $invitations = [];
        if (auth()->user()->isAdmin()) {
            $invitations = TeamInvitation::whereTeamId(auth()->user()->currentTeam()->id)->get();
        }
        return view('team.show', [
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
        } catch (\Throwable $th) {
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
        } catch (\Throwable $th) {
            throw $th;
        }
    }
}
