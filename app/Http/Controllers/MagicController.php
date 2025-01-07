<?php

namespace App\Http\Controllers;

use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;

class MagicController extends Controller
{
    public function servers()
    {
        return response()->json([
            'servers' => Server::isUsable()->get(),
        ]);
    }

    public function destinations()
    {
        return response()->json([
            'destinations' => Server::destinationsByServer(request()->query('server_id'))->sortBy('name'),
        ]);
    }

    public function projects()
    {
        return response()->json([
            'projects' => Project::ownedByCurrentTeam()->get(),
        ]);
    }

    public function environments()
    {
        $project = Project::ownedByCurrentTeam()->whereUuid(request()->query('project_uuid'))->first();
        if (! $project) {
            return response()->json([
                'environments' => [],
            ]);
        }

        return response()->json([
            'environments' => $project->environments,
        ]);
    }

    public function newProject()
    {
        $project = Project::firstOrCreate(
            ['name' => request()->query('name') ?? generate_random_name()],
            ['team_id' => currentTeam()->id]
        );

        return response()->json([
            'project_uuid' => $project->uuid,
        ]);
    }

    public function newEnvironment()
    {
        $environment = Environment::firstOrCreate(
            ['name' => request()->query('name') ?? generate_random_name()],
            ['project_id' => Project::ownedByCurrentTeam()->whereUuid(request()->query('project_uuid'))->firstOrFail()->id]
        );

        return response()->json([
            'environment_name' => $environment->name,
        ]);
    }

    public function newTeam()
    {
        $team = Team::create(
            [
                'name' => request()->query('name') ?? generate_random_name(),
                'personal_team' => false,
            ],
        );
        auth()->user()->teams()->attach($team, ['role' => 'admin']);
        refreshSession();

        return redirect(request()->header('Referer'));
    }
}
