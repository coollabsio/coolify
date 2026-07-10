<?php

namespace App\Http\Controllers;

use App\Models\Deployment;
use Illuminate\Http\Request;

class DeploymentController extends Controller
{
    public function index()
    {
        $deployments = Deployment::all();
        return response()->json($deployments);
    }

    public function store(Request $request)
    {
        $deployment = Deployment::create($request->all());
        return response()->json($deployment, 201);
    }

    public function show($id)
    {
        $deployment = Deployment::findOrFail($id);
        return response()->json($deployment);
    }

    public function update(Request $request, $id)
    {
        $deployment = Deployment::findOrFail($id);
        $deployment->update($request->all());
        return response()->json($deployment);
    }

    public function destroy($id)
    {
        $deployment = Deployment::findOrFail($id);
        $deployment->delete();
        return response()->json(null, 204);
    }
}
