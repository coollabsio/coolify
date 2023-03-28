<?php

namespace App\Http\Controllers;

class HomeController extends Controller
{
    public function show()
    {
        $projects = session('currentTeam')->projects;
        return view('home', ['projects' => $projects]);
    }
}
