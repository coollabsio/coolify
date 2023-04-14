<?php

namespace App\Http\Controllers;

class HomeController extends Controller
{
    public function show()
    {
        $projects = session('currentTeam')->load(['projects'])->projects;
        $servers = session('currentTeam')->load(['servers'])->servers;

        return view('home', [
            'servers' => $servers,
            'projects' => $projects
        ]);
    }
}
