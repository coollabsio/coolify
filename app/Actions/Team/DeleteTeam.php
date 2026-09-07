<?php

namespace App\Actions\Team;

use App\Models\Application;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DeleteTeam
{
    public function handle(Team $team, User $user): ?Team
    {
        $newTeam = DB::transaction(function () use ($team, $user): ?Team {
            $team = Team::query()->lockForUpdate()->findOrFail($team->id);

            $role = DB::table('team_user')
                ->where('team_id', $team->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->value('role');

            if ($role !== 'owner') {
                throw new AuthorizationException('Only team owners can delete a team.');
            }

            $hasRunningApplications = Application::query()
                ->whereHas('environment.project', fn ($query) => $query->where('team_id', $team->id))
                ->lockForUpdate()
                ->get(['id', 'status'])
                ->contains(fn (Application $application): bool => $application->isRunning());

            if ($hasRunningApplications) {
                throw new RuntimeException('Stop all running applications before deleting this team.');
            }

            if ($team->servers()->lockForUpdate()->get(['servers.id'])->isNotEmpty()) {
                throw new RuntimeException('Delete all team servers before deleting this team.');
            }

            if (! $team->isEmpty()) {
                throw new RuntimeException('Delete all team resources before deleting this team.');
            }

            $team->members()
                ->where('users.id', '!=', $user->id)
                ->get()
                ->each(function (User $member) use ($team): void {
                    $member->teams()->detach($team);
                    DB::table('sessions')->where('user_id', $member->id)->delete();
                });

            $team->delete();

            return $user->teams()->first();
        });

        Cache::forget("user:{$user->id}:team:{$team->id}");

        return $newTeam;
    }
}
