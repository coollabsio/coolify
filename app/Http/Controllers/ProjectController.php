<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    public function index()
    {
        return Project::all();
    }

    public function store(Request $request)
    {
        $project = Project::create($request->all());
        return response()->json($project, 201);
    }

    public function show($id)
    {
        return Project::findOrFail($id);
    }

    public function update(Request $request, $id)
    {
        $project = Project::findOrFail($id);
        $project->update($request->all());
        return response()->json($project, 200);
    }

    public function destroy($id)
    {
        $project = Project::findOrFail($id);
        $project->delete();
        return response()->json(null, 204);
    }

    public function addMember(Request $request, $id)
    {
        $project = Project::findOrFail($id);
        $user = User::findOrFail($request->user_id);
        $project->members()->attach($user->id);
        return response()->json($project, 200);
    }

    public function removeMember($id, $user_id)
    {
        $project = Project::findOrFail($id);
        $project->members()->detach($user_id);
        return response()->json(null, 204);
    }
}
