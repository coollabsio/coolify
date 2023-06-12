<?php

namespace App\Http\Controllers;

use App\Http\Livewire\Server\PrivateKey;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;

class MagicController extends Controller
{
    public function servers()
    {
        return response()->json([
            'servers' => Server::validated()->get()
        ]);
    }
    public function destinations()
    {
        return response()->json([
            'destinations' => Server::destinationsByServer(request()->query('server_id'))->sortBy('name')
        ]);
    }
    public function projects()
    {
        return response()->json([
            'projects' => Project::ownedByCurrentTeam()->get()
        ]);
    }
    public function environments()
    {
        return response()->json([
            'environments' => Project::ownedByCurrentTeam()->whereUuid(request()->query('project_uuid'))->first()->environments
        ]);
    }
    public function new_project()
    {
        $project = Project::firstOrCreate(
            ['name' => request()->query('name') ?? generate_random_name()],
            ['team_id' => session('currentTeam')->id]
        );
        return response()->json([
            'project_uuid' => $project->uuid
        ]);
    }
    public function new_environment()
    {
        $environment = Environment::firstOrCreate(
            ['name' => request()->query('name') ?? generate_random_name()],
            ['project_id' => Project::ownedByCurrentTeam()->whereUuid(request()->query('project_uuid'))->firstOrFail()->id]
        );
        return response()->json([
            'environment_name' => $environment->name,
        ]);
    }
}
