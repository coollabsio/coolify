<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Inertia\Inertia;

class InertiaController extends Controller
{
    public function dashboard()
    {
        return Inertia::render('Dashboard', [
            'projects' => Project::ownedByCurrentTeam()->orderBy('created_at')->get(['name', 'description','uuid']),
        ]);
    }

    public function projects()
    {
        return Inertia::render('Projects', [
            'user' => 'test',
        ]);
    }
}
